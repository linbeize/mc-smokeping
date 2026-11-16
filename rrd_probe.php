<?php
/**
 * rrd_probe.php — 每分钟由 cron 执行一次
 *
 *   * * * * * php /var/www/html/rrd_probe.php >/dev/null 2>&1
 *
 * 用法：
 *   php rrd_probe.php                 # 所有启用目标并发探测（cron 用）
 *   php rrd_probe.php --target=ID     # 只探测一个目标（内部子进程 / 手动调试）
 *   php rrd_probe.php -v              # 同时把日志打印到终端
 *
 * 相比旧版：
 *  - 只能 CLI 运行（旧版可被任何人通过 HTTP 触发，往 RRD 里写垃圾数据并占用 PHP 进程）
 *  - flock 防止上一轮没跑完时重叠执行
 *  - 每个目标一个子进程并发探测：旧版串行，目标一多（每个 ~13s）就超过 60 秒
 *  - 分位数用插值计算，probes_per_minute 不是 5 时也正确（旧版下标写死 1/3）
 *  - DNS/SRV 每轮只解析一次
 *  - 写 status/<id>.json（最新延迟、在线人数、版本、MOTD），后台和首页直接读
 *  - 自己写 data/probe.log 并自动轮转
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/rrd.php';
require __DIR__ . '/lib/mc_ping.php';
cli_only();

$opts = getopt('v', ['target:']);
$verbose = isset($opts['v']) || (function_exists('stream_isatty') && @stream_isatty(STDOUT));

function out(string $msg): void {
  global $verbose;
  app_log($msg);
  if ($verbose) echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}

if (!rrd_available()) { out('ERROR php-rrd extension not loaded'); exit(1); }

/* ============================ 子进程：探测单个目标 ============================ */

if (isset($opts['target'])) {
  $t = find_target((string)$opts['target']);
  if (!$t) { out("ERROR unknown target {$opts['target']}"); exit(1); }
  exit(probe_target($t) ? 0 : 1);
}

function r2(?float $v): ?float { return $v === null ? null : round($v, 2); }

function probe_target(array $t): bool {
  $id = $t['id'];
  if (!is_file(rrd_path($id))) {
    $err = null;
    if (!rrd_create_target($id, $err)) { out("ERROR $id rrd create failed: $err"); return false; }
    out("$id RRD created");
  }

  $N       = max(1, (int)$t['probes_per_minute']);
  $gapUs   = max(0, (int)$t['probe_interval_ms']) * 1000;
  $timeout = (int)$t['timeout_ms'];
  $proto   = (int)$t['protocol_version'];
  $runStart = microtime(true);
  $deadline = $runStart + MC3_PROBE_BUDGET_MS / 1000;

  $res = mc_resolve($t['host'], (int)$t['port'], (bool)$t['srv']);

  $vals = []; $fail = 0; $tried = 0; $lastErr = null; $info = []; $connectMs = [];
  for ($i = 0; $i < $N; $i++) {
    if (microtime(true) + $timeout / 1000 > $deadline) break; // 预算用完，不再探测（不算丢包）
    $tried++;
    $r = mc_status_ping($t['host'], (int)$t['port'], $timeout, $proto, $res);
    if ($r['ok']) {
      $vals[] = (float)$r['latency_ms'];
      if ($r['connect_ms'] !== null) $connectMs[] = $r['connect_ms'];
      if (!$info && $r['json']) $info = mc_status_info($r['json']);
    } else {
      $fail++;
      $lastErr = $r['err'];
    }
    if ($i < $N - 1 && $gapUs > 0) usleep($gapUs);
  }

  $loss = $tried > 0 ? $fail / $tried : 1.0;
  if ($vals) {
    sort($vals);
    $p = [percentile($vals, 0), percentile($vals, .25), percentile($vals, .5), percentile($vals, .75), percentile($vals, 1)];
    $fmt = array_map(function ($v) { return sprintf('%.3f', $v); }, $p);
  } else {
    $p = [null, null, null, null, null];
    $fmt = ['U', 'U', 'U', 'U', 'U'];
  }

  $update = 'N:' . implode(':', $fmt) . ':' . sprintf('%.6f', $loss);
  $ok = @rrd_update(rrd_path($id), [$update]);
  if (!$ok) out("ERROR $id rrd_update failed: " . rrd_error());

  $prev = read_status($id) ?? [];
  $status = array_merge([
    'id' => $id,
    'ts' => time(),
    'online' => (bool)$vals,
    'p0' => r2($p[0]), 'p25' => r2($p[1]), 'p50' => r2($p[2]), 'p75' => r2($p[3]), 'p100' => r2($p[4]),
    'loss' => round($loss, 4),
    'samples' => $tried,
    'connect_ms' => $connectMs ? round(array_sum($connectMs) / count($connectMs), 1) : null,
    'resolved' => $res['addr'] . ':' . $res['port'] . ($res['srv'] ? " (SRV {$res['srv']})" : ''),
    'error' => $vals ? null : $lastErr,
    'last_error' => $lastErr ?? ($prev['last_error'] ?? null),
    'last_online_ts' => $vals ? time() : ($prev['last_online_ts'] ?? null),
    'duration_ms' => (int)round((microtime(true) - $runStart) * 1000),
  ], $info ?: array_intersect_key($prev, array_flip(['version', 'protocol', 'players_online', 'players_max', 'motd'])));
  if (!$vals) $status['players_online'] = null;
  write_json(status_path($id), $status);

  out(sprintf('%s p50=%s loss=%.0f%% (%d/%d)%s', $id,
    $p[2] === null ? 'U' : sprintf('%.1fms', $p[2]), $loss * 100, $tried - $fail, $tried,
    $lastErr ? " err=$lastErr" : ''));
  return (bool)$ok;
}

/* ============================ 父进程：调度所有目标 ============================ */

$lock = fopen(data_dir() . '/probe.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
  out('WARN previous run still in progress, skipped');
  exit(0);
}

$t0 = microtime(true);
$list = targets(true);
$php = app_config()['php_binary'] ?: PHP_BINARY;
$maxPar = max(1, (int)(app_config()['max_parallel'] ?? 16));
$canSpawn = function_exists('proc_open') && $php && count($list) > 1;

if (!$canSpawn) {
  foreach ($list as $t) probe_target($t);   // 单目标或禁用了 proc_open：直接串行
} else {
  $queue = $list;
  $running = [];
  $hardDeadline = $t0 + 58;
  while ($queue || $running) {
    while ($queue && count($running) < $maxPar) {
      $t = array_shift($queue);
      $cmd = [$php, __FILE__, '--target=' . $t['id']];
      if ($verbose) $cmd[] = '-v';
      $p = @proc_open($cmd, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
      if (is_resource($p)) $running[$t['id']] = $p;
      else { out("WARN spawn failed for {$t['id']}, probing inline"); probe_target($t); }
    }
    foreach ($running as $id => $p) {
      $st = proc_get_status($p);
      if (!$st['running']) { proc_close($p); unset($running[$id]); }
      elseif (microtime(true) > $hardDeadline) {
        out("ERROR $id exceeded 58s, killed");
        proc_terminate($p, 9); proc_close($p); unset($running[$id]);
      }
    }
    if ($running) usleep(100000);
  }
}

write_json(heartbeat_path(), [
  'ts' => time(),
  'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
  'targets' => count($list),
  'php' => PHP_VERSION,
]);
flock($lock, LOCK_UN);
