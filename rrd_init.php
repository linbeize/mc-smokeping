<?php
/**
 * rrd_init.php — 为所有目标创建 RRD（可选；后台添加目标、或 cron 首次运行时也会自动创建）
 *   php rrd_init.php
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/rrd.php';
cli_only();

if (!rrd_available()) { fwrite(STDERR, "php-rrd extension not loaded\n"); exit(1); }

$rc = 0;
foreach (targets() as $t) {
  if (is_file(rrd_path($t['id']))) { echo "RRD already exists: " . rrd_path($t['id']) . "\n"; continue; }
  $err = null;
  if (rrd_create_target($t['id'], $err)) echo "Created RRD: " . rrd_path($t['id']) . "\n";
  else { fwrite(STDERR, "rrd_create failed ({$t['id']}): $err\n"); $rc = 1; }
}
exit($rc);
