<?php
// lib/bootstrap.php — 公共引导：配置、数据目录、settings.json 存取、目标校验、通用工具
if (defined('MC3_BOOT')) return;
define('MC3_BOOT', 1);
define('MC3_ROOT', dirname(__DIR__));
define('MC3_VERSION', '2.0.0');

// RRD 一次探测的时间预算：必须在 cron 的 60 秒内完成
define('MC3_PROBE_BUDGET_MS', 50000);

/* ------------------------------------------------------------------ 配置 */

function app_config(): array {
  static $c = null;
  if ($c === null) {
    $c = require MC3_ROOT . '/config.php';
    if (!is_array($c)) $c = [];
  }
  return $c;
}

function data_dir(): string {
  return rtrim(app_config()['data_dir'] ?? (MC3_ROOT . '/data'), '/\\');
}

function settings_path(): string { return data_dir() . '/settings.json'; }
function status_path(string $id): string { return data_dir() . '/status/' . $id . '.json'; }
function rrd_path(string $id): string { return data_dir() . '/' . $id . '.rrd'; }
function log_path(): string { return data_dir() . '/probe.log'; }
function heartbeat_path(): string { return data_dir() . '/heartbeat.json'; }

function ensure_data_dir(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $d = data_dir();
  foreach (['', '/status', '/cache', '/trash'] as $sub) {
    if (!is_dir($d . $sub)) @mkdir($d . $sub, 0775, true);
  }
  // 数据目录禁止直接 HTTP 访问（Apache）。nginx 请参考 README 配置 deny。
  $ht = $d . '/.htaccess';
  if (!file_exists($ht)) {
    @file_put_contents($ht,
      "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
      "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
  }
}

/* ------------------------------------------------------------------ JSON 文件 */

function read_json(string $path, $default = null) {
  if (!is_file($path)) return $default;
  $s = @file_get_contents($path);
  if ($s === false || $s === '') return $default;
  $d = json_decode($s, true);
  return is_array($d) ? $d : $default;
}

/** 原子写：先写临时文件再 rename，避免读到半截 JSON */
function write_json(string $path, $data): bool {
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  $tmp = $path . '.tmp' . getmypid();
  if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  @chmod($tmp, 0660);
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true;
}

/* ------------------------------------------------------------------ 设置 */

function default_settings(): array {
  return [
    'site' => [
      'title'    => 'MC 延迟监控',
      'timezone' => 'Asia/Shanghai',
      'public'   => true,   // false = 前台也需要登录
      'notice'   => '',     // 前台公告
    ],
    'admin' => [
      'username'      => 'admin',
      'password_hash' => '',
    ],
    'targets' => [],
  ];
}

function settings(bool $reload = false): array {
  static $s = null;
  if ($s !== null && !$reload) return $s;

  ensure_data_dir();
  $d = read_json(settings_path());
  if ($d === null) {
    // 首次运行：从 config.php 导入初始目标（兼容旧版 'targets' 键）
    $d = default_settings();
    $cfg = app_config();
    $seed = $cfg['seed_targets'] ?? ($cfg['targets'] ?? []);
    foreach ($seed as $t) {
      $errs = [];
      $n = normalize_target($t, null, $errs, $d['targets']);
      if ($n) $d['targets'][] = $n;
    }
    write_json(settings_path(), $d);
  }
  $d = array_replace_recursive(default_settings(), $d);
  $d['targets'] = array_values($d['targets'] ?? []);
  $s = $d;
  return $s;
}

function save_settings(array $s): bool {
  $ok = write_json(settings_path(), $s);
  settings(true);
  return $ok;
}

function site(string $key) { return settings()['site'][$key] ?? null; }

/* ------------------------------------------------------------------ 目标 */

function target_defaults(): array {
  return [
    'id' => '', 'name' => '', 'host' => '', 'port' => 25565,
    'timeout_ms' => 1500, 'probes_per_minute' => 5, 'probe_interval_ms' => 1500,
    'protocol_version' => 760, 'srv' => true, 'enabled' => true, 'public' => true,
    'note' => '',
  ];
}

/** @return array[] */
function targets(bool $onlyEnabled = false, bool $onlyPublic = false): array {
  $out = [];
  foreach (settings()['targets'] as $t) {
    $t = array_merge(target_defaults(), $t);
    if ($onlyEnabled && !$t['enabled']) continue;
    if ($onlyPublic && !$t['public']) continue;
    $out[] = $t;
  }
  return $out;
}

function find_target(string $id): ?array {
  foreach (targets() as $t) if ($t['id'] === $id) return $t;
  return null;
}

function valid_target_id(string $id): bool {
  return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id);
}

/**
 * 校验并规范化目标。$existing 不为 null 表示编辑（id 不可改）。
 * @return array|null 成功返回规范化后的目标；失败返回 null 并填充 $errors
 */
function normalize_target(array $in, ?array $existing, array &$errors, array $all): ?array {
  $d = array_merge(target_defaults(), $existing ?? []);
  $t = [];

  $t['id'] = $existing ? $existing['id'] : strtolower(trim((string)($in['id'] ?? '')));
  if (!$existing) {
    if (!valid_target_id($t['id'])) {
      $errors[] = 'ID 只能包含小写字母、数字、-、_，以字母或数字开头，最长 32 位';
    } else {
      foreach ($all as $o) if (($o['id'] ?? '') === $t['id']) { $errors[] = "ID「{$t['id']}」已存在"; break; }
    }
  }

  $t['name'] = trim((string)($in['name'] ?? $d['name']));
  if ($t['name'] === '') $t['name'] = $t['id'];
  if (u_len($t['name']) > 64) $errors[] = '名称最长 64 个字符';

  $t['host'] = trim((string)($in['host'] ?? $d['host']));
  if ($t['host'] === '' || strlen($t['host']) > 253 || !preg_match('/^[A-Za-z0-9.\-:]+$/', $t['host'])) {
    $errors[] = '主机地址无效（域名或 IP）';
  }

  $int = function (string $k, int $min, int $max, string $label) use ($in, $d, &$t, &$errors) {
    $v = $in[$k] ?? $d[$k];
    if (!is_numeric($v) || (int)$v != $v || (int)$v < $min || (int)$v > $max) {
      $errors[] = "{$label}需在 {$min} ~ {$max} 之间";
      $t[$k] = (int)$d[$k];
    } else {
      $t[$k] = (int)$v;
    }
  };
  $int('port', 1, 65535, '端口');
  $int('timeout_ms', 200, 10000, '超时');
  $int('probes_per_minute', 1, 20, '每分钟探测次数');
  $int('probe_interval_ms', 0, 10000, '探测间隔');
  $int('protocol_version', -1, 100000, '协议版本');

  $budget = $t['probes_per_minute'] * $t['timeout_ms'] + max(0, $t['probes_per_minute'] - 1) * $t['probe_interval_ms'];
  if ($budget > MC3_PROBE_BUDGET_MS) {
    $errors[] = sprintf('最坏情况下一轮探测要 %.1f 秒，超过 %d 秒预算（会导致 cron 重叠），请减少次数/超时/间隔',
      $budget / 1000, MC3_PROBE_BUDGET_MS / 1000);
  }

  foreach (['srv', 'enabled', 'public'] as $b) {
    $t[$b] = array_key_exists($b, $in) ? filter_var($in[$b], FILTER_VALIDATE_BOOLEAN) : (bool)$d[$b];
  }
  $t['note'] = u_sub(trim((string)($in['note'] ?? $d['note'])), 200);

  return $errors ? null : $t;
}

function read_status(string $id): ?array { return read_json(status_path($id)); }

/* ------------------------------------------------------------------ 日志 */

function app_log(string $msg): void {
  ensure_data_dir();
  $p = log_path();
  if (@filesize($p) > 2 * 1024 * 1024) @rename($p, $p . '.1');   // 简单轮转，保留 2MB×2
  @file_put_contents($p, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* ------------------------------------------------------------------ 时间 */

/** 支持 epoch 秒 / "YYYY-MM-DDTHH:MM" / 任意 strtotime 可解析格式（按站点时区） */
function parse_time_any($v): ?int {
  if ($v === null) return null;
  $v = trim((string)$v);
  if ($v === '') return null;
  if (ctype_digit($v)) return (int)$v;
  $ts = strtotime(str_replace('T', ' ', $v));
  return $ts === false ? null : $ts;
}

function range_map(): array {
  return [
    '1h' => ['1 小时', 3600], '6h' => ['6 小时', 6 * 3600], '12h' => ['12 小时', 12 * 3600],
    '1d' => ['1 天', 86400], '7d' => ['7 天', 7 * 86400], '1m' => ['30 天', 30 * 86400],
    '1y' => ['1 年', 365 * 86400],
  ];
}

/**
 * 统一解析 range/start/end 参数
 * @return array [startTs, endTs, rangeKey, isCustom] 或 [null, null, 错误信息, false]
 */
function resolve_time_window(array $q): array {
  $now = time();
  $map = range_map();
  $range = (string)($q['range'] ?? '1h');
  if (!isset($map[$range])) $range = '1h';

  $s = parse_time_any($q['start'] ?? null);
  $e = parse_time_any($q['end'] ?? null);
  $custom = ($s !== null && $e !== null);
  if (!$custom) { $e = $now; $s = $now - $map[$range][1]; }
  if ($e > $now) $e = $now;
  if ($s >= $e) return [null, null, '开始时间必须早于结束时间', false];
  $maxSpan = 370 * 86400;
  if ($e - $s > $maxSpan) $s = $e - $maxSpan;
  return [$s, $e, $range, $custom];
}

function human_ago(?int $ts): string {
  if (!$ts) return '从未';
  $d = time() - $ts;
  if ($d < 0) $d = 0;
  if ($d < 60) return $d . ' 秒前';
  if ($d < 3600) return floor($d / 60) . ' 分钟前';
  if ($d < 86400) return floor($d / 3600) . ' 小时前';
  return floor($d / 86400) . ' 天前';
}

/* ------------------------------------------------------------------ HTTP 工具 */

// mbstring 不一定安装，提供兜底
function u_len(string $s): int {
  return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int)preg_match_all('/./us', $s);
}
function u_sub(string $s, int $len): string {
  if (function_exists('mb_substr')) return mb_substr($s, 0, $len, 'UTF-8');
  return preg_match('/^.{0,' . $len . '}/us', $s, $m) ? $m[0] : '';
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function json_out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

function cli_only(): void {
  if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit;
  }
}

/* ------------------------------------------------------------------ 初始化 */

ensure_data_dir();
$__tz = (string)(settings()['site']['timezone'] ?? 'Asia/Shanghai');
if (!in_array($__tz, timezone_identifiers_list(), true)) $__tz = 'Asia/Shanghai';
date_default_timezone_set($__tz);
unset($__tz);
