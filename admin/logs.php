<?php
// admin/logs.php — 查看采集日志
require __DIR__ . '/_inc.php';
require_admin();

$n = max(50, min(2000, (int)($_GET['n'] ?? 300)));
$q = trim((string)($_GET['q'] ?? ''));
$onlyErr = !empty($_GET['err']);

$lines = tail_lines(log_path(), $q !== '' || $onlyErr ? 5000 : $n);
if ($q !== '') $lines = array_values(array_filter($lines, function ($l) use ($q) { return stripos($l, $q) !== false; }));
if ($onlyErr) $lines = array_values(array_filter($lines, function ($l) { return preg_match('/\b(ERROR|WARN)\b|err=/', $l); }));
$lines = array_slice($lines, -$n);

admin_header('采集日志', 'logs');
?>
<div class="card">
  <form class="row" method="get" style="margin-bottom:12px">
    <input name="q" value="<?= h($q) ?>" placeholder="按目标 ID / 关键字过滤">
    <select name="n"><?php foreach ([100, 300, 1000, 2000] as $o): ?><option <?= $o === $n ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select>
    <label class="chk"><input type="checkbox" name="err" value="1" <?= $onlyErr ? 'checked' : '' ?>> 只看错误</label>
    <button class="btn">筛选</button>
    <span style="flex:1"></span>
    <span class="muted small"><?= h(log_path()) ?><?= is_file(log_path()) ? '（' . round(filesize(log_path()) / 1024) . ' KB）' : '' ?></span>
  </form>
  <?php if (!$lines): ?>
    <div class="muted">暂无日志。cron 运行后这里会出现每分钟的采集记录。</div>
  <?php else: ?>
    <pre class="log"><?php foreach (array_reverse($lines) as $l):
      $cls = preg_match('/\bERROR\b/', $l) ? 'e' : (preg_match('/\bWARN\b|err=/', $l) ? 'w' : '');
      echo $cls ? '<span class="' . $cls . '">' . h($l) . '</span>' : h($l), "\n";
    endforeach; ?></pre>
    <div class="muted small" style="margin-top:6px">最新在最上方，显示 <?= count($lines) ?> 行</div>
  <?php endif; ?>
</div>
<?php admin_footer();
