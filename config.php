<?php
// /var/www/html/config.php
// 部署级配置（很少改动）。监控目标、站点设置、管理员密码都在后台 /admin/ 管理，
// 实际保存在 data_dir/settings.json。
return [
  // 数据目录（RRD、状态、日志、后台设置）。
  // 强烈建议放到 Web 根目录之外，例如 '/var/lib/mc3'（记得 chown 给 php 运行用户）。
  'data_dir' => __DIR__ . '/data',

  // cron 并发探测时调用的 php 可执行文件；留空 = 使用当前 CLI 的 PHP_BINARY
  'php_binary' => '',

  // 同时并发探测的目标数上限
  'max_parallel' => 16,

  // 首次运行时导入的初始目标（settings.json 生成后以后台为准，这里不再生效）
  'seed_targets' => [
    [
      'id' => 'hypixel',
      'name' => 'Hypixel',
      'host' => 'mc.hypixel.net',
      'port' => 25565,
      'timeout_ms' => 1500,
      'probes_per_minute' => 5,
      'probe_interval_ms' => 1500,
      'protocol_version' => 760,
    ],
  ],
];
