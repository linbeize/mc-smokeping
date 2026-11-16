<?php
// index.php — 前台：所有目标状态总览 + 交互式延迟图
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require_view();

$list = visible_targets();
$ids = array_column($list, 'id');
$target = (string)($_GET['target'] ?? '');
if (!in_array($target, $ids, true)) $target = $ids[0] ?? '';

[$startTs, $endTs, $range, $custom] = resolve_time_window($_GET);
if ($startTs === null) { [$startTs, $endTs, $range, $custom] = resolve_time_window([]); }

$status = [];
foreach ($list as $t) {
  $s = read_status($t['id']) ?? [];
  $status[] = [
    'id' => $t['id'], 'name' => $t['name'], 'host' => $t['host'], 'port' => $t['port'], 'enabled' => $t['enabled'],
    'stale' => !isset($s['ts']) || time() - $s['ts'] > 180,
  ] + array_intersect_key($s, array_flip(['ts', 'online', 'p50', 'p0', 'p100', 'loss', 'version', 'players_online', 'players_max', 'motd']));
}

$boot = [
  'tz' => date_default_timezone_get(),
  'target' => $target,
  'range' => $range,
  'start' => $custom ? $startTs : null,
  'end' => $custom ? $endTs : null,
  'ranges' => array_map(function ($v) { return $v[0]; }, range_map()),
  'status' => $status,
  'now' => time(),
];
$title = (string)site('title');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= MC3_VERSION ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1><?= h($title) ?></h1>
    <span class="sp"></span>
    <span class="muted small" id="updated"></span>
    <a class="btn sm" href="admin/">管理后台</a>
  </div>

  <?php if (trim((string)site('notice')) !== ''): ?>
    <div class="notice"><?= h(site('notice')) ?></div>
  <?php endif; ?>

  <?php if (!$list): ?>
    <div class="card">还没有监控目标。请到 <a href="admin/">管理后台</a> 添加。</div>
  <?php else: ?>

  <div class="grid" id="cards"></div>

  <div class="card">
    <div class="row" style="margin-bottom:12px">
      <h2 style="margin:0" id="chartTitle"></h2>
      <span class="sp" style="flex:1"></span>
      <div class="seg" id="ranges"></div>
    </div>

    <div id="chart"></div>
    <noscript>
      <img src="graph.php?target=<?= h(urlencode($target)) ?>&range=<?= h($range) ?>" style="max-width:100%" alt="">
    </noscript>

    <div class="legend">
      <span><i style="background:var(--smoke1)"></i>最小~最大</span>
      <span><i style="background:var(--smoke2)"></i>P25~P75</span>
      <span><i style="background:#16a34a"></i>中位数（无丢包）</span>
      <span><i style="background:#2563eb"></i>≤20%</span>
      <span><i style="background:#7c3aed"></i>≤50%</span>
      <span><i style="background:#dc2626"></i>&gt;50% / 丢包背景</span>
      <span>· 在图上<b>拖选</b>可放大</span>
    </div>
    <div class="stats" id="stats"></div>
  </div>

  <div class="card">
    <form class="row" id="customForm" method="get" style="align-items:flex-end">
      <input type="hidden" name="target" value="<?= h($target) ?>">
      <div class="col"><span class="muted">开始时间</span><input type="datetime-local" name="start" id="inStart"></div>
      <div class="col"><span class="muted">结束时间</span><input type="datetime-local" name="end" id="inEnd"></div>
      <button class="btn primary" type="submit">查看该时间段</button>
      <button class="btn" type="button" id="btnReset" style="display:none">退出自定义</button>
      <span class="sp" style="flex:1"></span>
      <a class="btn" id="btnPng" target="_blank" rel="noopener">下载 PNG</a>
    </form>
    <div class="muted small" style="margin-top:8px">时间按 <?= h($boot['tz']) ?> 显示。每分钟采样一次，RRD 自动降采样：1 天内 1 分钟粒度，7 天内 5 分钟，35 天内 15 分钟，更久 1 小时。</div>
  </div>
  <?php endif; ?>
</div>

<script src="assets/chart.js?v=<?= MC3_VERSION ?>"></script>
<script>
(() => {
  const B = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  if (!B.target) return;
  const U = SmokeUtil, $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const state = { target: B.target, range: B.range, start: B.start, end: B.end };
  let statusList = B.status, reqSeq = 0;

  const chart = new SmokeChart($('chart'), { tz: B.tz, onZoom: (a, b) => go({ start: a, end: b }) });

  // ---------- 状态卡片 ----------
  function renderCards() {
    $('cards').innerHTML = statusList.map(s => {
      let cls = 'bad', txt = '离线';
      if (!s.enabled) { cls = ''; txt = '已停用'; }
      else if (s.stale) { cls = 'warn'; txt = s.ts ? '数据过期' : '等待数据'; }
      else if (s.online) { cls = s.loss > 0 ? 'warn' : 'ok'; txt = s.loss > 0 ? '丢包 ' + U.fmtPct(s.loss) : '在线'; }
      const players = s.players_online != null ? `${s.players_online}${s.players_max ? ' / ' + s.players_max : ''} 人` : '';
      return `<a class="tcard ${s.id === state.target ? 'sel' : ''}" href="?target=${encodeURIComponent(s.id)}" data-id="${esc(s.id)}" title="${esc(s.motd || '')}">
        <div class="nm"><span class="dot ${cls}"></span>${esc(s.name)}</div>
        <div class="host">${esc(s.host)}${s.port != 25565 ? ':' + s.port : ''}</div>
        <div class="big">${s.online && !s.stale ? U.fmtMs(s.p50) : '—'} <small>${txt}</small></div>
        <div class="meta">${players ? `<span>${esc(players)}</span>` : ''}${s.version ? `<span>${esc(s.version)}</span>` : ''}</div>
      </a>`;
    }).join('');
    const cur = statusList.find(s => s.id === state.target);
    $('chartTitle').textContent = cur ? cur.name : state.target;
  }
  $('cards').addEventListener('click', e => {
    const a = e.target.closest('a.tcard');
    if (!a || e.ctrlKey || e.metaKey) return;
    e.preventDefault();
    go({ target: a.dataset.id });
  });

  async function refreshStatus() {
    try {
      const r = await fetch('api.php?action=status', { cache: 'no-store' });
      const j = await r.json();
      if (j.ok) { statusList = j.targets; renderCards(); }
    } catch (e) {}
  }

  // ---------- 时间范围按钮 ----------
  $('ranges').innerHTML = Object.entries(B.ranges).map(([k, v]) => `<button type="button" class="btn sm" data-r="${k}">${k}</button>`).join('');
  $('ranges').addEventListener('click', e => {
    const b = e.target.closest('button[data-r]');
    if (b) go({ range: b.dataset.r, start: null, end: null });
  });

  function syncUi() {
    const custom = state.start != null;
    document.querySelectorAll('#ranges button').forEach(b => b.classList.toggle('active', !custom && b.dataset.r === state.range));
    $('btnReset').style.display = custom ? '' : 'none';
    document.querySelector('#customForm [name=target]').value = state.target;
    const now = Math.floor(Date.now() / 1000);
    const span = { '1h': 3600, '6h': 21600, '12h': 43200, '1d': 86400, '7d': 604800, '1m': 2592000, '1y': 31536000 }[state.range] || 3600;
    $('inStart').value = U.fmtTs(custom ? state.start : now - span, B.tz, 'input');
    $('inEnd').value = U.fmtTs(custom ? state.end : now, B.tz, 'input');
    $('btnPng').href = 'graph.php?' + qs({ w: 1200, h: 360, download: 1 });
  }

  function qs(extra) {
    const p = new URLSearchParams({ target: state.target });
    if (state.start != null) { p.set('start', state.start); p.set('end', state.end); }
    else p.set('range', state.range);
    for (const k in extra || {}) p.set(k, extra[k]);
    return p.toString();
  }

  async function loadChart() {
    const seq = ++reqSeq;
    const rows = Math.max(200, Math.min(1500, Math.round($('chart').clientWidth)));
    try {
      const r = await fetch('api.php?action=series&' + qs({ rows }), { cache: 'no-store' });
      const j = await r.json();
      if (seq !== reqSeq) return;               // 期间切换了视图，丢弃过期响应
      if (!j.ok) { chart.data = null; chart.draw(); chart.message(j.error || '加载失败'); $('stats').innerHTML = ''; return; }
      chart.setData(j);
      renderStats();
    } catch (e) {
      if (seq === reqSeq) chart.message('加载失败：' + e.message);
    }
  }

  function renderStats() {
    const s = chart.stats(); if (!s) return;
    const items = [
      ['当前', U.fmtMs(s.last)], ['平均', U.fmtMs(s.avg)], ['最低', U.fmtMs(s.min)], ['最高', U.fmtMs(s.max)],
      ['抖动 (sd)', U.fmtMs(s.sd)], ['平均丢包', U.fmtPct(s.lossAvg)], ['最大丢包', U.fmtPct(s.lossMax)], ['可用率', U.fmtPct(s.uptime)],
    ];
    $('stats').innerHTML = items.map(([k, v]) => `<div class="stat"><div class="k">${k}</div><div class="v">${v}</div></div>`).join('');
  }

  function go(patch, push = true) {
    Object.assign(state, patch);
    if (push) history.pushState({ ...state }, '', '?' + qs());
    renderCards(); syncUi(); loadChart();
  }
  window.addEventListener('popstate', e => { if (e.state) go(e.state, false); });
  history.replaceState({ ...state }, '', '?' + qs());

  // 自定义区间：datetime-local 按站点时区换算为 epoch（旧版用 toISOString() 得到 UTC，与服务器时区差 8 小时）
  function inputToTs(v) {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(v); if (!m) return null;
    const guess = Date.UTC(+m[1], m[2] - 1, +m[3], +m[4], +m[5]) / 1000;
    return guess - U.tzOffset(guess - U.tzOffset(guess, B.tz), B.tz);
  }
  $('customForm').addEventListener('submit', e => {
    e.preventDefault();
    const a = inputToTs($('inStart').value), b = inputToTs($('inEnd').value);
    if (a == null || b == null || a >= b) { alert('请填写有效的开始/结束时间，且开始早于结束'); return; }
    go({ start: a, end: b });
  });
  $('btnReset').addEventListener('click', () => go({ start: null, end: null }));

  renderCards(); syncUi(); loadChart();

  // 自动刷新：状态每分钟；非自定义区间时图表每分钟
  setInterval(() => {
    if (document.hidden) return;
    refreshStatus();
    if (state.start == null) loadChart();
    $('updated').textContent = '更新于 ' + U.fmtTs(Date.now() / 1000, B.tz, 'time');
  }, 60000);
  $('updated').textContent = '更新于 ' + U.fmtTs(B.now, B.tz, 'time');
})();
</script>
</body>
</html>
