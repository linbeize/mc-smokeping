<?php
// lib/rrd.php — RRD 相关封装

function rrd_available(): bool { return function_exists('rrd_update'); }

/**
 * DS 顺序：p0 p25 p50 p75 p100 loss；step 60s
 * 相比旧版：
 *  - 延迟上限 5000 → 60000ms（超时可配到 10s，避免被截断成 UNKNOWN）
 *  - 新增 15 分钟/35 天 档，30 天视图不再退化成 1 小时粒度
 *  - 各档行数留余量（旧版 7 天/1 年 刚好卡边，跨度稍大就掉到更粗一档）
 */
function rrd_create_target(string $id, ?string &$err = null): bool {
  if (!rrd_available()) { $err = 'php-rrd 扩展未加载'; return false; }
  $p = rrd_path($id);
  if (file_exists($p)) return true;
  $opts = ['--step', '60'];
  foreach (['p0', 'p25', 'p50', 'p75', 'p100'] as $ds) $opts[] = "DS:$ds:GAUGE:120:0:60000";
  $opts[] = 'DS:loss:GAUGE:120:0:1';
  array_push($opts,
    'RRA:AVERAGE:0.5:1:1500',    // 1 分钟 × ~25 小时
    'RRA:AVERAGE:0.5:5:2100',    // 5 分钟 × ~7.3 天
    'RRA:AVERAGE:0.5:15:3360',   // 15 分钟 × 35 天
    'RRA:AVERAGE:0.5:60:9000'    // 1 小时 × ~375 天
  );
  if (!@rrd_create($p, $opts)) { $err = rrd_error(); return false; }
  @chmod($p, 0664);
  return true;
}

/** 线性插值分位数；N=5 时恰好等于旧版的下标 0/1/2/3/4 */
function percentile(array $sorted, float $q): float {
  $n = count($sorted);
  if ($n === 1) return (float)$sorted[0];
  $pos = $q * ($n - 1);
  $lo = (int)floor($pos);
  $hi = (int)ceil($pos);
  return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
}

/**
 * 导出时间段数据（给前端 canvas 画图用）
 * @return array|string 成功返回数组，失败返回错误字符串
 */
function rrd_series(string $id, int $start, int $end, int $maxRows = 800) {
  if (!rrd_available()) return 'php-rrd 扩展未加载';
  $p = rrd_path($id);
  if (!is_file($p)) return 'RRD 文件不存在，请等待首次采集或在后台创建';
  $dsList = ['p0', 'p25', 'p50', 'p75', 'p100', 'loss'];
  $opts = ['--start', (string)$start, '--end', (string)$end, '--maxrows', (string)max(50, min(2000, $maxRows))];
  $pe = str_replace(':', '\:', $p);
  foreach ($dsList as $ds) $opts[] = "DEF:$ds=$pe:$ds:AVERAGE";
  foreach ($dsList as $ds) $opts[] = "XPORT:$ds:$ds";
  $x = @rrd_xport($opts);
  if (!is_array($x)) return 'rrd_xport 失败: ' . rrd_error();

  $out = ['start' => (int)$x['start'], 'end' => (int)$x['end'], 'step' => (int)$x['step'], 'ts' => []];
  foreach ($dsList as $ds) $out[$ds] = [];
  $first = true;
  foreach ($x['data'] as $col) {
    $name = $col['legend'] ?? '';
    if (!in_array($name, $dsList, true)) continue;
    foreach ($col['data'] as $ts => $v) {
      if ($first) $out['ts'][] = (int)$ts;
      $out[$name][] = (is_float($v) && (is_nan($v) || is_infinite($v))) || $v === null
        ? null : round((float)$v, $name === 'loss' ? 4 : 2);
    }
    $first = false;
  }
  return $out;
}
