<?php
// admin/_inc.php — 后台公共：引入库、页头页脚
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/rrd.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function admin_header(string $title, string $active = ''): void {
  $nav = [
    'index' => ['index.php', '仪表盘'],
    'targets' => ['targets.php', '监控目标'],
    'settings' => ['settings.php', '系统设置'],
    'logs' => ['logs.php', '采集日志'],
  ];
  ?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<title><?= h($title) ?> · 管理后台</title>
<link rel="stylesheet" href="../assets/app.css?v=<?= MC3_VERSION ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1><?= h((string)site('title')) ?> <span class="muted" style="font-size:14px;font-weight:400">管理后台</span></h1>
  </div>
  <nav class="admin-nav">
    <?php foreach ($nav as $k => [$href, $label]): ?>
      <a href="<?= $href ?>" class="<?= $k === $active ? 'on' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <span class="sp"></span>
    <a href="../" target="_blank">查看前台 ↗</a>
    <form method="post" action="logout.php" style="margin:0"><?= csrf_field() ?><button class="btn sm" type="submit">退出（<?= h(settings()['admin']['username']) ?>）</button></form>
  </nav>
  <?php foreach (take_flash() as [$type, $msg]): ?>
    <div class="alert <?= $type === 'ok' ? 'ok' : ($type === 'warn' ? 'warn' : 'err') ?>"><?= h($msg) ?></div>
  <?php endforeach;
}

function admin_footer(): void {
  ?>
  <div class="muted small" style="text-align:center;margin-top:24px">MC Smokeping v<?= MC3_VERSION ?> · PHP <?= PHP_VERSION ?></div>
</div>
<script>
// 后台通用：带 CSRF 的 POST
window.postJSON = async (url, data) => {
  const fd = new FormData();
  for (const k in data) fd.append(k, data[k]);
  const r = await fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf]').content } });
  let j; try { j = await r.json(); } catch (e) { j = { ok: false, error: 'HTTP ' + r.status }; }
  return j;
};
</script>
</body>
</html>
<?php
}

function status_badge(array $t, ?array $s): string {
  if (!$t['enabled']) return '<span class="tag">已停用</span>';
  if (!$s || !isset($s['ts'])) return '<span class="tag warn">等待数据</span>';
  if (time() - $s['ts'] > 180) return '<span class="tag warn">数据过期</span>';
  if (empty($s['online'])) return '<span class="tag bad">离线</span>';
  if (($s['loss'] ?? 0) > 0) return '<span class="tag warn">丢包 ' . round($s['loss'] * 100) . '%</span>';
  return '<span class="tag ok">在线</span>';
}

function fmt_ms($v): string { return $v === null ? '—' : sprintf($v >= 100 ? '%.0f ms' : '%.1f ms', $v); }

/** 读取日志尾部（只读最后 N 字节，不把整个文件读进内存） */
function tail_lines(string $path, int $lines, int $maxBytes = 524288): array {
  if (!is_file($path)) return [];
  $size = filesize($path);
  $fp = fopen($path, 'rb');
  if (!$fp) return [];
  $read = min($size, $maxBytes);
  fseek($fp, $size - $read);
  $data = (string)fread($fp, $read);
  fclose($fp);
  $arr = preg_split('/\r?\n/', rtrim($data));
  if ($read < $size) array_shift($arr);   // 第一行可能不完整
  return array_slice($arr, -$lines);
}
