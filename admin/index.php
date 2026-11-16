<?php
// admin/index.php — 仪表盘：系统自检 + 目标状态总览
require __DIR__ . '/_inc.php';
require_admin();

$list = targets();
$hb = read_json(heartbeat_path());
$hbAge = $hb ? time() - (int)$hb['ts'] : null;

$dd = data_dir();
$rootReal = realpath(MC3_ROOT) ?: MC3_ROOT;
$ddReal = realpath($dd) ?: $dd;
$ddInWeb = strpos(str_replace('\\', '/', $ddReal) . '/', str_replace('\\', '/', $rootReal) . '/') === 0;

$checks = [
  [version_compare(PHP_VERSION, '7.4', '>='), 'PHP ' . PHP_VERSION, '需要 PHP 7.4 及以上'],
  [rrd_available(), 'php-rrd 扩展', rrd_available() ? '已加载' : '未加载：apt install php-rrd 后重启 php-fpm'],
  [is_writable($dd), '数据目录可写', $dd],
  [$hbAge !== null && $hbAge < 150, 'cron 采集',
    $hbAge === null ? '从未运行过。请添加 crontab：* * * * * php ' . MC3_ROOT . '/rrd_probe.php >/dev/null 2>&1'
      : '上次 ' . human_ago($hb['ts']) . '，耗时 ' . round($hb['duration_ms'] / 1000, 1) . ' 秒，' . $hb['targets'] . ' 个目标'],
  [function_exists('proc_open'), '并发探测 (proc_open)', function_exists('proc_open') ? '可用' : '被禁用，将串行探测，目标多时可能超过 60 秒'],
  [!$ddInWeb ? true : null, '数据目录位置', $ddInWeb ? '位于 Web 根目录内，Apache 已用 .htaccess 拦截；nginx 需手动 deny（见 README），或在 config.php 中把 data_dir 移出' : '在 Web 根目录之外 ✓'],
];

$on = $off = $wait = 0; $sum = 0; $n = 0;
foreach ($list as $t) {
  if (!$t['enabled']) continue;
  $s = read_status($t['id']);
  if (!$s || time() - $s['ts'] > 180) $wait++;
  elseif (!empty($s['online'])) { $on++; if ($s['p50'] !== null) { $sum += $s['p50']; $n++; } }
  else $off++;
}

admin_header('仪表盘', 'index');
?>
<div class="stats" style="margin:0 0 16px">
  <div class="stat"><div class="k">目标总数</div><div class="v"><?= count($list) ?></div></div>
  <div class="stat"><div class="k">在线</div><div class="v" style="color:var(--ok)"><?= $on ?></div></div>
  <div class="stat"><div class="k">离线</div><div class="v" style="color:var(--bad)"><?= $off ?></div></div>
  <div class="stat"><div class="k">无数据/过期</div><div class="v" style="color:var(--warn)"><?= $wait ?></div></div>
  <div class="stat"><div class="k">平均延迟</div><div class="v"><?= $n ? fmt_ms($sum / $n) : '—' ?></div></div>
</div>

<div class="card">
  <h2>系统自检</h2>
  <div class="checks">
    <?php foreach ($checks as [$ok, $name, $desc]): ?>
      <div class="check">
        <span class="dot <?= $ok === true ? 'ok' : ($ok === null ? 'warn' : 'bad') ?>" style="margin-top:6px"></span>
        <div><b><?= h($name) ?></b><div class="muted small" style="word-break:break-all"><?= h($desc) ?></div></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="row" style="margin-bottom:8px">
    <h2 style="margin:0">目标状态</h2><span style="flex:1"></span>
    <a class="btn sm primary" href="target_edit.php">+ 添加目标</a>
    <a class="btn sm" href="targets.php">管理</a>
  </div>
  <?php if (!$list): ?>
    <div class="muted">还没有目标，<a href="target_edit.php">添加一个</a>。</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>名称</th><th>状态</th><th>中位延迟</th><th>最小 / 最大</th><th>丢包</th><th>在线人数</th><th>版本</th><th>更新</th></tr>
    <?php foreach ($list as $t): $s = read_status($t['id']); ?>
      <tr>
        <td><a href="../?target=<?= h(urlencode($t['id'])) ?>" target="_blank"><b><?= h($t['name']) ?></b></a><div class="muted small"><?= h($t['host'] . ':' . $t['port']) ?></div></td>
        <td><?= status_badge($t, $s) ?><?php if (!empty($s['error'])): ?><div class="muted small" title="<?= h($s['error']) ?>"><?= h(u_sub($s['error'], 40)) ?></div><?php endif; ?></td>
        <td><b><?= fmt_ms($s['p50'] ?? null) ?></b></td>
        <td class="muted"><?= fmt_ms($s['p0'] ?? null) ?> / <?= fmt_ms($s['p100'] ?? null) ?></td>
        <td><?= isset($s['loss']) ? round($s['loss'] * 100) . '%' : '—' ?></td>
        <td><?= isset($s['players_online']) ? h($s['players_online'] . ' / ' . ($s['players_max'] ?? '?')) : '—' ?></td>
        <td class="small"><?= h($s['version'] ?? '—') ?></td>
        <td class="muted small"><?= human_ago($s['ts'] ?? null) ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<script>setTimeout(() => { if (!document.hidden) location.reload(); }, 60000);</script>
<?php admin_footer();
