<?php
// admin/target_edit.php — 添加 / 编辑目标
require __DIR__ . '/_inc.php';
require_admin();

$id = (string)($_GET['id'] ?? '');
$existing = $id !== '' ? find_target($id) : null;
if ($id !== '' && !$existing) { flash('目标不存在', 'err'); header('Location: targets.php'); exit; }

$errors = [];
$form = $existing ?? target_defaults();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  require_post_csrf();
  $in = $_POST;
  foreach (['srv', 'enabled', 'public'] as $b) $in[$b] = !empty($_POST[$b]);   // 未勾选的复选框不会提交
  $s = settings();
  $n = normalize_target($in, $existing, $errors, $s['targets']);
  if ($n) {
    if ($existing) {
      foreach ($s['targets'] as $i => $t) if ($t['id'] === $existing['id']) $s['targets'][$i] = $n;
    } else {
      $s['targets'][] = $n;
    }
    if (!save_settings($s)) {
      $errors[] = '保存失败：' . settings_path() . ' 不可写';
    } else {
      $msg = $existing ? "已保存「{$n['name']}」" : "已添加「{$n['name']}」，下一分钟开始采集";
      if (!$existing && rrd_available()) {
        $err = null;
        if (!rrd_create_target($n['id'], $err)) $msg .= '（RRD 创建失败：' . $err . '，cron 会再次尝试）';
      }
      if ($existing && ($existing['host'] !== $n['host'] || $existing['port'] !== $n['port'])) {
        $msg .= '。注意：地址已更改，历史曲线会与新地址的数据混在一起；如需区分请新建目标';
      }
      flash($msg);
      header('Location: targets.php');
      exit;
    }
  }
  $form = array_merge($form, array_intersect_key($in, target_defaults()));
}

admin_header($existing ? '编辑目标' : '添加目标', 'targets');
?>
<div class="card">
  <h2><?= $existing ? '编辑：' . h($existing['name']) : '添加监控目标' ?></h2>
  <?php if ($errors): ?>
    <div class="alert err"><?= implode('<br>', array_map('h', $errors)) ?></div>
  <?php endif; ?>

  <form method="post" id="tform">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field">
        <label>ID <?= $existing ? '' : '<span style="color:var(--bad)">*</span>' ?></label>
        <input name="id" value="<?= h($form['id']) ?>" <?= $existing ? 'disabled' : 'required pattern="[a-z0-9][a-z0-9_\-]{0,31}"' ?> placeholder="例如 hypixel">
        <div class="hint"><?= $existing ? 'ID 对应 RRD 文件名，创建后不可修改' : '小写字母/数字/-/_，用作 RRD 文件名，创建后不可修改' ?></div>
      </div>
      <div class="field">
        <label>显示名称</label>
        <input name="name" value="<?= h($form['name']) ?>" maxlength="64" placeholder="例如 Hypixel">
      </div>
      <div class="field">
        <label>主机地址 <span style="color:var(--bad)">*</span></label>
        <input name="host" value="<?= h($form['host']) ?>" required placeholder="mc.hypixel.net 或 IP">
      </div>
      <div class="field">
        <label>端口</label>
        <input name="port" type="number" min="1" max="65535" value="<?= h($form['port']) ?>">
      </div>
      <div class="field">
        <label>每分钟探测次数</label>
        <input name="probes_per_minute" type="number" min="1" max="20" value="<?= h($form['probes_per_minute']) ?>">
        <div class="hint">用于计算分位数（烟雾带）和丢包率，5 次即可</div>
      </div>
      <div class="field">
        <label>单次超时（毫秒）</label>
        <input name="timeout_ms" type="number" min="200" max="10000" value="<?= h($form['timeout_ms']) ?>">
        <div class="hint">一次完整探测（连接+握手+状态+Ping）的总时限</div>
      </div>
      <div class="field">
        <label>探测间隔（毫秒）</label>
        <input name="probe_interval_ms" type="number" min="0" max="10000" value="<?= h($form['probe_interval_ms']) ?>">
      </div>
      <div class="field">
        <label>握手协议版本</label>
        <input name="protocol_version" type="number" min="-1" max="100000" value="<?= h($form['protocol_version']) ?>">
        <div class="hint">查询状态时多数服务器不校验；-1 表示"未知"。个别服务器不响应时可改成其版本号</div>
      </div>
      <div class="field full">
        <label>备注</label>
        <input name="note" value="<?= h($form['note']) ?>" maxlength="200" placeholder="仅后台可见">
      </div>
      <div class="field full row" style="gap:20px">
        <label class="chk"><input type="checkbox" name="enabled" value="1" <?= $form['enabled'] ? 'checked' : '' ?>> 启用采集</label>
        <label class="chk"><input type="checkbox" name="public" value="1" <?= $form['public'] ? 'checked' : '' ?>> 前台可见</label>
        <label class="chk"><input type="checkbox" name="srv" value="1" <?= $form['srv'] ? 'checked' : '' ?>> 解析 SRV 记录（_minecraft._tcp）</label>
      </div>
    </div>

    <div class="muted small" id="budget" style="margin-top:12px"></div>

    <div class="row" style="margin-top:14px">
      <button class="btn primary" type="submit">保存</button>
      <button class="btn" type="button" id="btnTest">🔌 测试连接</button>
      <a class="btn" href="targets.php">取消</a>
    </div>
  </form>
  <div id="testResult" style="margin-top:14px"></div>
</div>

<script>
(() => {
  const f = document.getElementById('tform');
  const v = n => parseInt(f.elements[n].value || '0', 10);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function budget() {
    const n = v('probes_per_minute'), ms = n * v('timeout_ms') + Math.max(0, n - 1) * v('probe_interval_ms');
    const el = document.getElementById('budget');
    el.textContent = `最坏情况下一轮耗时 ${(ms / 1000).toFixed(1)} 秒（上限 <?= MC3_PROBE_BUDGET_MS / 1000 ?> 秒）`;
    el.style.color = ms > <?= MC3_PROBE_BUDGET_MS ?> ? 'var(--bad)' : '';
  }
  ['probes_per_minute', 'timeout_ms', 'probe_interval_ms'].forEach(n => f.elements[n].addEventListener('input', budget));
  budget();

  document.getElementById('btnTest').addEventListener('click', async e => {
    const btn = e.currentTarget, out = document.getElementById('testResult');
    btn.disabled = true; out.innerHTML = '<div class="muted">测试中…（连续探测 3 次）</div>';
    const j = await postJSON('api.php?action=test', {
      host: f.host.value, port: f.port.value, timeout_ms: f.timeout_ms.value,
      protocol_version: f.protocol_version.value, srv: f.srv.checked ? 1 : 0,
    });
    btn.disabled = false;
    if (!j.ok && !j.results) { out.innerHTML = `<div class="alert err">${esc(j.error)}</div>`; return; }
    const okN = j.results.filter(r => r.ok).length;
    out.innerHTML = `<div class="alert ${okN ? 'ok' : 'err'}">
      <b>${okN}/${j.results.length} 次成功</b> · 解析到 <span class="mono">${esc(j.resolved)}</span><br>
      ${j.results.map((r, i) => `#${i + 1}: ${r.ok ? `Ping <b>${r.latency_ms} ms</b>（TCP 连接 ${r.connect_ms} ms）` : '<span style="color:var(--bad)">' + esc(r.err) + '</span>'}`).join('<br>')}
      ${j.info && j.info.version ? `<hr style="border:0;border-top:1px solid var(--border)">
        版本：${esc(j.info.version)}（协议 ${esc(j.info.protocol)}）<br>
        在线：${esc(j.info.players_online)} / ${esc(j.info.players_max)}<br>
        MOTD：${esc(j.info.motd)}` : ''}
    </div>`;
    if (j.info && j.info.protocol && !f.protocol_version.dataset.touched && confirm('是否把握手协议版本设为服务器报告的 ' + j.info.protocol + '？')) {
      f.protocol_version.value = j.info.protocol;
    }
  });
  f.protocol_version.addEventListener('input', () => f.protocol_version.dataset.touched = 1);
})();
</script>
<?php admin_footer();
