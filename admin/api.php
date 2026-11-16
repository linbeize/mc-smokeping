<?php
// admin/api.php — 后台 AJAX 接口
require __DIR__ . '/_inc.php';
require dirname(__DIR__) . '/lib/mc_ping.php';
require_admin(true);

$action = $_GET['action'] ?? '';

if ($action === 'test') {
  require_post_csrf(true);
  $host = trim((string)($_POST['host'] ?? ''));
  $port = (int)($_POST['port'] ?? 25565);
  $timeout = max(200, min(10000, (int)($_POST['timeout_ms'] ?? 1500)));
  $proto = (int)($_POST['protocol_version'] ?? 760);
  $srv = !empty($_POST['srv']);
  if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:]+$/', $host) || $port < 1 || $port > 65535) {
    json_out(['ok' => false, 'error' => '地址或端口无效'], 400);
  }
  session_write_close();   // 测试可能耗时数秒，别锁住会话
  @set_time_limit(60);

  $res = mc_resolve($host, $port, $srv);
  $results = []; $info = null;
  for ($i = 0; $i < 3; $i++) {
    $r = mc_status_ping($host, $port, $timeout, $proto, $res);
    if ($r['ok'] && !$info && $r['json']) $info = mc_status_info($r['json']);
    $results[] = [
      'ok' => $r['ok'],
      'latency_ms' => $r['latency_ms'] !== null ? round($r['latency_ms'], 1) : null,
      'connect_ms' => $r['connect_ms'] !== null ? round($r['connect_ms'], 1) : null,
      'err' => $r['err'],
    ];
    if ($i < 2) usleep(300000);
  }
  json_out([
    'ok' => (bool)array_filter(array_column($results, 'ok')),
    'resolved' => $res['addr'] . ':' . $res['port'] . ($res['srv'] ? " (SRV → {$res['srv']})" : ''),
    'results' => $results,
    'info' => $info,
  ]);
}

json_out(['ok' => false, 'error' => 'unknown action'], 400);
