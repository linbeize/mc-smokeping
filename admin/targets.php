<?php
// admin/targets.php — 目标列表 + 启停/公开/排序/删除
require __DIR__ . '/_inc.php';
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  require_post_csrf();
  $id = (string)($_POST['id'] ?? '');
  $act = (string)($_POST['action'] ?? '');
  $s = settings();
  $idx = null;
  foreach ($s['targets'] as $i => $t) if ($t['id'] === $id) { $idx = $i; break; }

  if ($idx === null) {
    flash('目标不存在', 'err');
  } else {
    $t = array_merge(target_defaults(), $s['targets'][$idx]);
    switch ($act) {
      case 'toggle_enabled':
      case 'toggle_public':
        $k = $act === 'toggle_enabled' ? 'enabled' : 'public';
        $s['targets'][$idx][$k] = !$t[$k];
        save_settings($s);
        flash("「{$t['name']}」已" . ($k === 'enabled' ? ($t[$k] ? '停用' : '启用') : ($t[$k] ? '设为隐藏' : '设为公开')));
        break;

      case 'up':
      case 'down':
        $j = $act === 'up' ? $idx - 1 : $idx + 1;
        if (isset($s['targets'][$j])) {
          [$s['targets'][$idx], $s['targets'][$j]] = [$s['targets'][$j], $s['targets'][$idx]];
          save_settings($s);
        }
        break;

      case 'delete':
        array_splice($s['targets'], $idx, 1);
        save_settings($s);
        @unlink(status_path($id));
        $rrd = rrd_path($id);
        if (is_file($rrd)) {
          if (!empty($_POST['purge'])) @unlink($rrd);
          else @rename($rrd, data_dir() . '/trash/' . $id . '-' . date('Ymd-His') . '.rrd');
        }
        flash("已删除「{$t['name']}」" . (!empty($_POST['purge']) ? '及其历史数据' : '，历史数据已移到 data/trash/'));
        break;

      case 'create_rrd':
        $err = null;
        if (rrd_create_target($id, $err)) flash('RRD 已就绪');
        else flash('创建 RRD 失败：' . $err, 'err');
        break;
    }
  }
  header('Location: targets.php');
  exit;
}

$list = targets();
admin_header('监控目标', 'targets');
?>
<div class="card">
  <div class="row" style="margin-bottom:10px">
    <h2 style="margin:0">监控目标（<?= count($list) ?>）</h2>
    <span style="flex:1"></span>
    <a class="btn primary" href="target_edit.php">+ 添加目标</a>
  </div>
  <?php if (!$list): ?>
    <div class="muted">还没有目标。</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>#</th><th>名称 / ID</th><th>地址</th><th>探测参数</th><th>状态</th><th>启用</th><th>前台可见</th><th>RRD</th><th style="text-align:right">操作</th></tr>
    <?php foreach ($list as $i => $t):
      $s = read_status($t['id']);
      $rrd = rrd_path($t['id']);
      $hasRrd = is_file($rrd); ?>
      <tr>
        <td class="muted">
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($t['id']) ?>">
            <button class="btn sm" name="action" value="up" <?= $i === 0 ? 'disabled' : '' ?> title="上移">↑</button><button class="btn sm" name="action" value="down" <?= $i === count($list) - 1 ? 'disabled' : '' ?> title="下移">↓</button>
          </form>
        </td>
        <td><b><?= h($t['name']) ?></b><div class="muted small mono"><?= h($t['id']) ?></div><?php if ($t['note']): ?><div class="muted small"><?= h($t['note']) ?></div><?php endif; ?></td>
        <td class="mono small"><?= h($t['host'] . ':' . $t['port']) ?><?php if (!empty($s['resolved'])): ?><div class="muted"><?= h($s['resolved']) ?></div><?php endif; ?></td>
        <td class="small muted"><?= (int)$t['probes_per_minute'] ?> 次/分 · 超时 <?= (int)$t['timeout_ms'] ?>ms<br>间隔 <?= (int)$t['probe_interval_ms'] ?>ms · 协议 <?= (int)$t['protocol_version'] ?><?= $t['srv'] ? ' · SRV' : '' ?></td>
        <td><?= status_badge($t, $s) ?><div class="small"><?= fmt_ms($s['p50'] ?? null) ?></div></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($t['id']) ?>"><button class="btn sm" name="action" value="toggle_enabled"><?= $t['enabled'] ? '✅ 启用' : '⏸ 停用' ?></button></form></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($t['id']) ?>"><button class="btn sm" name="action" value="toggle_public"><?= $t['public'] ? '👁 公开' : '🔒 隐藏' ?></button></form></td>
        <td class="small">
          <?php if ($hasRrd): ?>
            <span class="muted"><?= round(filesize($rrd) / 1024) ?> KB</span>
          <?php else: ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($t['id']) ?>"><button class="btn sm" name="action" value="create_rrd">创建</button></form>
          <?php endif; ?>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn sm" href="target_edit.php?id=<?= h(urlencode($t['id'])) ?>">编辑</a>
          <a class="btn sm" href="../?target=<?= h(urlencode($t['id'])) ?>" target="_blank">图表</a>
          <form method="post" style="display:inline" onsubmit="return confirmDel(this, <?= h(json_encode($t['name'], JSON_UNESCAPED_UNICODE)) ?>)">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= h($t['id']) ?>"><input type="hidden" name="purge" value="">
            <button class="btn sm danger" name="action" value="delete">删除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <div class="muted small" style="margin-top:10px">停用：不再采集，前台不显示，历史数据保留。隐藏：照常采集，仅登录后可见。</div>
</div>
<script>
function confirmDel(form, name) {
  if (!confirm('确定删除「' + name + '」？')) return false;
  form.purge.value = confirm('是否同时永久删除历史数据？\n\n确定 = 永久删除\n取消 = 移到 data/trash/ 保留') ? '1' : '';
  return true;
}
</script>
<?php admin_footer();
