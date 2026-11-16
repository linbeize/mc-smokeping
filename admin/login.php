<?php
require __DIR__ . '/_inc.php';

$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
// 只允许站内相对路径跳转，防开放重定向
if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || strpos($next, '\\') !== false) $next = 'index.php';

if (is_admin()) { header('Location: ' . $next); exit; }

$setup = !admin_password_set();
$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!csrf_ok()) {
    $error = '页面已过期，请重试';
  } elseif ($setup) {
    $user = trim((string)($_POST['username'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (!hash_equals(setup_token(), trim((string)($_POST['token'] ?? '')))) $error = '初始化令牌不正确';
    elseif (!preg_match('/^[A-Za-z0-9_.-]{2,32}$/', $user)) $error = '用户名 2~32 位，字母数字 _ . -';
    elseif (strlen($pw) < 8) $error = '密码至少 8 位';
    elseif ($pw !== ($_POST['password2'] ?? '')) $error = '两次密码不一致';
    elseif (!set_admin_password($user, $pw)) $error = '保存失败：请检查 ' . data_dir() . ' 是否可写';
    else { login_as_admin(); flash('初始化完成，欢迎使用！'); header('Location: index.php'); exit; }
  } else {
    $wait = login_blocked($ip);
    if ($wait > 0) {
      $error = '失败次数过多，请 ' . ceil($wait / 60) . ' 分钟后再试';
    } else {
      $a = settings()['admin'];
      $okUser = hash_equals((string)$a['username'], (string)($_POST['username'] ?? ''));
      $okPw = password_verify((string)($_POST['password'] ?? ''), (string)$a['password_hash']);
      if ($okUser && $okPw) {
        login_record($ip, true);
        if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) set_admin_password($a['username'], (string)$_POST['password']);
        login_as_admin();
        header('Location: ' . $next);
        exit;
      }
      login_record($ip, false);
      usleep(random_int(200000, 500000));
      $error = '用户名或密码错误';
    }
  }
}
if ($setup) setup_token(); // 确保令牌文件存在
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $setup ? '初始化' : '登录' ?> · <?= h((string)site('title')) ?></title>
<link rel="stylesheet" href="../assets/app.css?v=<?= MC3_VERSION ?>">
</head>
<body>
<div class="login-box">
  <div class="card">
    <h2><?= h((string)site('title')) ?> · <?= $setup ? '首次初始化' : '管理员登录' ?></h2>
    <?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>
    <?php if ($setup): ?>
      <div class="alert warn small">
        为防止他人抢先设置密码，请在服务器上执行以下任一命令获取初始化令牌：<br>
        <code>cat <?= h(setup_token_path()) ?></code><br>
        <code>php <?= h(MC3_ROOT) ?>/cli.php setup-token</code><br>
        也可以直接用 <code>php cli.php passwd</code> 在命令行设置密码。
      </div>
    <?php endif; ?>
    <form method="post" class="col" style="gap:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <?php if ($setup): ?>
        <div class="field"><label>初始化令牌</label><input name="token" required autocomplete="off"></div>
      <?php endif; ?>
      <div class="field"><label>用户名</label><input name="username" required value="<?= h($_POST['username'] ?? ($setup ? 'admin' : '')) ?>" autocomplete="username"></div>
      <div class="field"><label>密码</label><input type="password" name="password" required autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>"></div>
      <?php if ($setup): ?>
        <div class="field"><label>确认密码</label><input type="password" name="password2" required autocomplete="new-password"></div>
      <?php endif; ?>
      <button class="btn primary" type="submit" style="justify-content:center"><?= $setup ? '设置并登录' : '登录' ?></button>
    </form>
  </div>
  <div class="muted small" style="text-align:center"><a href="../">← 返回前台</a></div>
</div>
</body>
</html>
