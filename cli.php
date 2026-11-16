<?php
/**
 * cli.php — 命令行管理工具
 *   php cli.php passwd [用户名]   设置/重置后台管理员密码（忘记密码时用）
 *   php cli.php setup-token        显示网页首次初始化令牌
 *   php cli.php status             查看各目标最新状态
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
cli_only();

$cmd = $argv[1] ?? '';
switch ($cmd) {
  case 'passwd':
    $user = $argv[2] ?? (settings()['admin']['username'] ?: 'admin');
    echo "为用户 {$user} 设置新密码（至少 8 位）: ";
    if (PHP_OS_FAMILY !== 'Windows') @shell_exec('stty -echo');
    $pw = trim((string)fgets(STDIN));
    if (PHP_OS_FAMILY !== 'Windows') { @shell_exec('stty echo'); echo PHP_EOL; }
    if (strlen($pw) < 8) { fwrite(STDERR, "密码太短\n"); exit(1); }
    if (!set_admin_password($user, $pw)) { fwrite(STDERR, "保存失败：检查 " . settings_path() . " 是否可写\n"); exit(1); }
    echo "已保存。所有已登录会话已失效。\n";
    break;

  case 'setup-token':
    if (admin_password_set()) { echo "管理员密码已设置，无需令牌。忘记密码请用: php cli.php passwd\n"; break; }
    echo setup_token() . PHP_EOL;
    break;

  case 'status':
    foreach (targets() as $t) {
      $s = read_status($t['id']);
      printf("%-16s %-28s %s  p50=%s loss=%s  %s\n", $t['id'], $t['host'] . ':' . $t['port'],
        $t['enabled'] ? 'ON ' : 'OFF',
        isset($s['p50']) ? sprintf('%.1fms', $s['p50']) : '-',
        isset($s['loss']) ? sprintf('%.0f%%', $s['loss'] * 100) : '-',
        isset($s['ts']) ? human_ago($s['ts']) : '无数据');
    }
    break;

  default:
    echo "用法:\n  php cli.php passwd [用户名]\n  php cli.php setup-token\n  php cli.php status\n";
}
