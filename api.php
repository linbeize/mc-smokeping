<?php
// api.php — 前台只读 JSON 接口
//   api.php?action=status                               所有可见目标的最新状态
//   api.php?action=series&target=ID&range=1d           图表数据（或 &start=..&end=..，epoch 秒）
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/rrd.php';
require_view(true);

$action = $_GET['action'] ?? 'status';

if ($action === 'status') {
  $list = [];
  foreach (visible_targets() as $t) {
    $s = read_status($t['id']) ?? [];
    $list[] = [
      'id' => $t['id'], 'name' => $t['name'], 'host' => $t['host'], 'port' => $t['port'],
      'enabled' => $t['enabled'],
      'stale' => !isset($s['ts']) || time() - $s['ts'] > 180,
    ] + array_intersect_key($s, array_flip(['ts', 'online', 'p50', 'p0', 'p100', 'loss', 'version', 'players_online', 'players_max', 'motd']));
  }
  json_out(['ok' => true, 'now' => time(), 'targets' => $list]);
}

if ($action === 'series') {
  $t = find_target((string)($_GET['target'] ?? ''));
  if (!can_view_target($t)) json_out(['ok' => false, 'error' => '目标不存在'], 404);
  [$s, $e, $range, $custom] = resolve_time_window($_GET);
  if ($s === null) json_out(['ok' => false, 'error' => $range], 400);
  $rows = (int)($_GET['rows'] ?? 800);
  $data = rrd_series($t['id'], $s, $e, $rows);
  if (is_string($data)) json_out(['ok' => false, 'error' => $data], 500);
  json_out(['ok' => true, 'target' => $t['id'], 'name' => $t['name'], 'req_start' => $s, 'req_end' => $e, 'custom' => $custom] + $data);
}

json_out(['ok' => false, 'error' => 'unknown action'], 400);
