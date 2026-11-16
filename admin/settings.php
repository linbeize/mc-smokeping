<?php
// admin/settings.php — 站点设置 + 修改账号密码
require __DIR__ . '/_inc.php';
require_admin();

$errors = [];
$s = settings();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  require_post_csrf();
  $form = (string)($_POST['form'] ?? '');

  if ($form === 'site') {
    $title = trim((string)($_POST['title'] ?? ''));
    $tz = (string)($_POST['timezone'] ?? '');
    if ($title === '' || u_len($title) > 60) $errors[] = '站点标题 1~60 个字符';
    if (!in_array($tz, timezone_identifiers_list(), true)) $errors[] = '时区无效';
    if (!$errors) {
      $s['site']['title'] = $title;
      $s['site']['timezone'] = $tz;
      $s['site']['public'] = !empty($_POST['public']);
      $s['site']['notice'] = u_sub(trim((string)($_POST['notice'] ?? '')), 1000);
      if (save_settings($s)) { flash('站点设置已保存'); header('Location: settings.php'); exit; }
      $errors[] = '保存失败：' . settings_path() . ' 不可写';
    }
  }

  if ($form === 'account') {
    $user = trim((string)($_POST['username'] ?? ''));
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify($cur, $s['admin']['password_hash'])) $errors[] = '当前密码不正确';
    elseif (!preg_match('/^[A-Za-z0-9_.-]{2,32}$/', $user)) $errors[] = '用户名 2~32 位，字母数字 _ . -';
    elseif ($new !== '' && strlen($new) < 8) $errors[] = '新密码至少 8 位';
    elseif ($new !== ($_POST['new2'] ?? '')) $errors[] = '两次输入的新密码不一致';
    else {
      if (set_admin_password($user, $new !== '' ? $new : $cur)) {
        login_as_admin();   // 刷新本会话指纹，其他设备上的会话失效
        flash($new !== '' ? '密码已修改，其他设备上的登录已失效' : '用户名已修改');
        header('Location: settings.php');
        exit;
      }
      $errors[] = '保存失败';
    }
  }
}

$site = $s['site'];
$common = ['Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Taipei', 'Asia/Tokyo', 'Asia/Singapore', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles', 'UTC'];
admin_header('系统设置', 'settings');
?>
<?php if ($errors): ?><div class="alert err"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<div class="card">
  <h2>站点设置</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="form" value="site">
    <div class="form-grid">
      <div class="field"><label>站点标题</label><input name="title" value="<?= h($site['title']) ?>" maxlength="60"></div>
      <div class="field">
        <label>时区</label>
        <select name="timezone">
          <optgroup label="常用">
            <?php foreach ($common as $z): ?><option <?= $z === $site['timezone'] ? 'selected' : '' ?>><?= h($z) ?></option><?php endforeach; ?>
          </optgroup>
          <optgroup label="全部">
            <?php foreach (timezone_identifiers_list() as $z): if (in_array($z, $common, true)) continue; ?>
              <option <?= $z === $site['timezone'] ? 'selected' : '' ?>><?= h($z) ?></option>
            <?php endforeach; ?>
          </optgroup>
        </select>
        <div class="hint">影响图表时间轴、日志时间、自定义时间段解析</div>
      </div>
      <div class="field full">
        <label class="chk"><input type="checkbox" name="public" value="1" <?= $site['public'] ? 'checked' : '' ?>> 前台公开访问（取消勾选后，查看图表也需要登录）</label>
      </div>
      <div class="field full">
        <label>前台公告</label>
        <textarea name="notice" rows="3" maxlength="1000" placeholder="留空则不显示"><?= h($site['notice']) ?></textarea>
      </div>
    </div>
    <button class="btn primary" style="margin-top:12px">保存</button>
  </form>
</div>

<div class="card">
  <h2>账号与密码</h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="form" value="account">
    <div class="form-grid">
      <div class="field"><label>用户名</label><input name="username" value="<?= h($s['admin']['username']) ?>" autocomplete="username"></div>
      <div class="field"><label>当前密码 <span style="color:var(--bad)">*</span></label><input type="password" name="current" required autocomplete="current-password"></div>
      <div class="field"><label>新密码</label><input type="password" name="new" autocomplete="new-password" placeholder="留空 = 不修改"></div>
      <div class="field"><label>确认新密码</label><input type="password" name="new2" autocomplete="new-password"></div>
    </div>
    <button class="btn primary" style="margin-top:12px">更新账号</button>
    <div class="muted small" style="margin-top:8px">忘记密码：在服务器执行 <code>php <?= h(MC3_ROOT) ?>/cli.php passwd</code></div>
  </form>
</div>

<div class="card">
  <h2>部署信息</h2>
  <table class="tbl">
    <tr><td class="muted" style="width:160px">数据目录</td><td class="mono small"><?= h(data_dir()) ?></td></tr>
    <tr><td class="muted">设置文件</td><td class="mono small"><?= h(settings_path()) ?></td></tr>
    <tr><td class="muted">crontab</td><td class="mono small">* * * * * php <?= h(MC3_ROOT) ?>/rrd_probe.php &gt;/dev/null 2&gt;&amp;1</td></tr>
    <tr><td class="muted">手动采集调试</td><td class="mono small">php <?= h(MC3_ROOT) ?>/rrd_probe.php -v</td></tr>
  </table>
</div>
<?php admin_footer();
