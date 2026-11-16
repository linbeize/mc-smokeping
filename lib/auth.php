<?php
// lib/auth.php — 后台登录、会话、CSRF、登录限速
require_once __DIR__ . '/bootstrap.php';

const MC3_SESSION_IDLE = 12 * 3600;  // 12 小时无操作自动退出
const MC3_LOGIN_MAX_FAIL = 5;        // 15 分钟内同一 IP 最多失败 5 次
const MC3_LOGIN_WINDOW = 900;

function session_boot(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  session_name('MC3SID');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
  session_start();
}

/** 密码哈希指纹：改密码后旧会话自动失效 */
function pw_fingerprint(): string {
  return substr(hash('sha256', (string)settings()['admin']['password_hash']), 0, 16);
}

function admin_password_set(): bool { return settings()['admin']['password_hash'] !== ''; }

function is_admin(): bool {
  session_boot();
  if (empty($_SESSION['mc3_admin'])) return false;
  if (($_SESSION['mc3_pwfp'] ?? '') !== pw_fingerprint()) return false;
  if (time() - (int)($_SESSION['mc3_seen'] ?? 0) > MC3_SESSION_IDLE) return false;
  $_SESSION['mc3_seen'] = time();
  return true;
}

function require_admin(bool $json = false): void {
  if (is_admin()) return;
  if ($json) json_out(['ok' => false, 'error' => '未登录或会话已过期'], 401);
  $next = $_SERVER['REQUEST_URI'] ?? '';
  header('Location: login.php' . ($next ? '?next=' . urlencode($next) : ''));
  exit;
}

function login_as_admin(): void {
  session_boot();
  session_regenerate_id(true);
  $_SESSION['mc3_admin'] = 1;
  $_SESSION['mc3_pwfp'] = pw_fingerprint();
  $_SESSION['mc3_seen'] = time();
}

function logout_admin(): void {
  session_boot();
  $_SESSION = [];
  session_regenerate_id(true);
  session_destroy();
}

function csrf_token(): string {
  session_boot();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }

function csrf_ok(): bool {
  session_boot();
  $t = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function require_post_csrf(bool $json = false): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_ok()) {
    if ($json) json_out(['ok' => false, 'error' => 'CSRF 校验失败，请刷新页面'], 403);
    http_response_code(403);
    exit('CSRF 校验失败，请返回刷新页面后重试');
  }
}

function flash(string $msg, string $type = 'ok'): void { session_boot(); $_SESSION['flash'][] = [$type, $msg]; }
function take_flash(): array { session_boot(); $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

/* ---------------- 前台访问控制 ---------------- */

/** 只有带了会话 cookie 才去开 session，普通访客不产生 session 文件 */
function viewer_is_admin(): bool {
  static $r = null;
  if ($r === null) $r = isset($_COOKIE['MC3SID']) && is_admin();
  return $r;
}

/** 站点设为私有时，前台也需要登录 */
function require_view(bool $api = false): void {
  if (site('public') || viewer_is_admin()) return;
  if ($api) json_out(['ok' => false, 'error' => '需要登录'], 401);
  header('Location: admin/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
  exit;
}

function can_view_target(?array $t): bool {
  if (!$t) return false;
  if ($t['public'] && $t['enabled']) return true;
  return viewer_is_admin();
}

/** 当前访客可见的目标 */
function visible_targets(): array {
  return viewer_is_admin() ? targets() : targets(true, true);
}

/* ---------------- 登录限速 ---------------- */

function login_guard_path(): string { return data_dir() . '/login_guard.json'; }

function login_blocked(string $ip): int {
  $g = read_json(login_guard_path(), []);
  $e = $g[$ip] ?? null;
  if (!$e || time() - $e['first'] > MC3_LOGIN_WINDOW) return 0;
  return $e['count'] >= MC3_LOGIN_MAX_FAIL ? MC3_LOGIN_WINDOW - (time() - $e['first']) : 0;
}

function login_record(string $ip, bool $success): void {
  $g = read_json(login_guard_path(), []);
  foreach ($g as $k => $e) if (time() - $e['first'] > MC3_LOGIN_WINDOW) unset($g[$k]);
  if ($success) unset($g[$ip]);
  else {
    $e = $g[$ip] ?? ['first' => time(), 'count' => 0];
    $e['count']++;
    $g[$ip] = $e;
  }
  write_json(login_guard_path(), $g);
}

/* ---------------- 首次初始化令牌 ---------------- */

function setup_token_path(): string { return data_dir() . '/setup_token.txt'; }

/** 未设置密码时生成一次性令牌，必须从服务器上读取，防止别人抢先初始化 */
function setup_token(): string {
  $p = setup_token_path();
  $t = is_file($p) ? trim((string)file_get_contents($p)) : '';
  if (strlen($t) < 16) {
    $t = bin2hex(random_bytes(12));
    @file_put_contents($p, $t . "\n");
    @chmod($p, 0600);
  }
  return $t;
}

function set_admin_password(string $username, string $password): bool {
  $s = settings();
  $s['admin']['username'] = $username;
  $s['admin']['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
  $ok = save_settings($s);
  if ($ok) @unlink(setup_token_path());
  return $ok;
}
