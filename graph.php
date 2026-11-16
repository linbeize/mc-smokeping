<?php
// graph.php — 生成 Smokeping 风格 PNG（用于下载/分享/嵌入；首页默认使用交互式 canvas 图）
//   graph.php?target=hypixel&range=1d
//   graph.php?target=hypixel&start=1731715200&end=1731801600&w=1200&h=400
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/rrd.php';
require_view(true);

function fail(int $code, string $msg): void {
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  echo $msg;
  exit;
}

if (!rrd_available()) fail(500, 'php-rrd extension not loaded');

$t = find_target((string)($_GET['target'] ?? ''));
if (!$t && !isset($_GET['target'])) $t = visible_targets()[0] ?? null;
if (!can_view_target($t)) fail(404, 'target not found');

[$startTs, $endTs, $range, $custom] = resolve_time_window($_GET);
if ($startTs === null) fail(400, $range);

// 尺寸：旧版靠 UA 判断手机，这里改为参数（前端按屏幕宽度传），并限制范围
$w = max(400, min(2000, (int)($_GET['w'] ?? 900)));
$h = max(120, min(800, (int)($_GET['h'] ?? 250)));

$rrd = rrd_path($t['id']);
if (!is_file($rrd)) fail(404, 'RRD not found, wait for first probe');

// 预设区间对齐到分钟 → 同一分钟内的请求命中缓存（旧版每次请求都重画，刷新/多人访问很费 CPU）
if (!$custom) { $endTs -= $endTs % 60; $startTs = $endTs - range_map()[$range][1]; }
$cacheDir = data_dir() . '/cache';
$key = md5(implode('|', [$t['id'], $startTs, $endTs, $w, $h, filemtime(settings_path())]));
$file = "$cacheDir/g_$key.png";

if (!is_file($file) || time() - filemtime($file) > 55) {
  $title = $t['name'] . ' — ' . ($custom ? date('m-d H:i', $startTs) . ' ~ ' . date('m-d H:i', $endTs) : range_map()[$range][0]);
  $pe = str_replace(':', '\:', $rrd);
  $opts = [
    '--start', (string)$startTs, '--end', (string)$endTs,
    '--width', (string)$w, '--height', (string)$h,
    '--lower-limit', '0', '--rigid', '--slope-mode',
    '--units-exponent', '0',               // 旧版 label 写 "Seconds" 但数据是毫秒，且大数值会被加 k 前缀
    '--title', $title,
    '--vertical-label', 'ms',
    '--color', 'BACK#FFFFFF', '--color', 'SHADEA#FFFFFF', '--color', 'SHADEB#FFFFFF',
    "DEF:p0=$pe:p0:AVERAGE",   "DEF:p25=$pe:p25:AVERAGE", "DEF:p50=$pe:p50:AVERAGE",
    "DEF:p75=$pe:p75:AVERAGE", "DEF:p100=$pe:p100:AVERAGE", "DEF:loss=$pe:loss:AVERAGE",
    'CDEF:d1=p25,p0,-', 'CDEF:d2=p50,p25,-', 'CDEF:d3=p75,p50,-', 'CDEF:d4=p100,p75,-',
    'AREA:p0#FFFFFF00',            // 透明底座（旧版画成浅灰，0~p0 也被涂了一块）
    'STACK:d1#DDDDDD', 'STACK:d2#BBBBBB', 'STACK:d3#BBBBBB', 'STACK:d4#DDDDDD',
    // 中位线按丢包着色（Smokeping 配色）
    'CDEF:loss_pct=loss,100,*',
    'CDEF:m0=loss_pct,0,LE,p50,UNKN,IF',
    'CDEF:m1=loss_pct,0,GT,loss_pct,20,LE,*,p50,UNKN,IF',
    'CDEF:m2=loss_pct,20,GT,loss_pct,50,LE,*,p50,UNKN,IF',
    'CDEF:m3=loss_pct,50,GT,p50,UNKN,IF',
    'LINE2:m0#00C000:loss 0%',
    'LINE2:m1#0090FF:<=20%',
    'LINE2:m2#8000FF:<=50%',
    'LINE2:m3#FF0000:>50%\l',
    'VDEF:med_sd=p50,STDEV',
    'COMMENT:median rtt\: ',
    'GPRINT:p50:AVERAGE:%6.1lf ms avg',
    'GPRINT:p50:MAX:%6.1lf ms max',
    'GPRINT:p50:MIN:%6.1lf ms min',
    'GPRINT:p50:LAST:%6.1lf ms now',
    'GPRINT:med_sd:%6.1lf ms sd\l',
    'COMMENT:packet loss\: ',
    'GPRINT:loss_pct:AVERAGE:%5.2lf %% avg',
    'GPRINT:loss_pct:MAX:%5.2lf %% max',
    'GPRINT:loss_pct:LAST:%5.2lf %% now\l',
    'COMMENT:Generated ' . str_replace(':', '\:', date('Y-m-d H:i:s')) . '\r',
  ];

  // 旧版 tempnam().'.png' 会在 /tmp 留下一个永远不删的空文件；这里写到缓存目录再原子替换
  $tmp = "$file.tmp" . getmypid();
  if (@rrd_graph($tmp, $opts) === false) { @unlink($tmp); fail(500, 'rrd_graph failed: ' . rrd_error()); }
  @rename($tmp, $file);

  // 顺手清理过期缓存（约 2% 概率触发）
  if (mt_rand(1, 50) === 1) foreach (glob("$cacheDir/g_*.png") ?: [] as $f) if (time() - @filemtime($f) > 600) @unlink($f);
}

header('Content-Type: image/png');
header('Cache-Control: ' . ($custom ? 'public, max-age=300' : 'public, max-age=30'));
header('Content-Length: ' . filesize($file));
if (isset($_GET['download'])) header('Content-Disposition: attachment; filename="' . $t['id'] . '-' . date('Ymd-His', $endTs) . '.png"');
readfile($file);
