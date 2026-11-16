<?php
// lib/mc_ping.php — Minecraft Java 版 Server List Ping
//
// 相比旧版的改进：
//  - 整个探测共享一个截止时间（timeout_ms 是总预算），不会因为多次分段超时拖到几倍时长
//  - 用 stream_select 等待数据，替代 fread+usleep(1ms) 忙等
//  - 直接在字符串上解析 VarInt，不再反复开 php://memory
//  - 限制包长度，防止恶意/异常服务器发巨大长度导致内存爆掉
//  - fwrite 检查部分写入
//  - 支持 SRV 记录（_minecraft._tcp），DNS 每轮只解析一次
//  - 状态 JSON 解析失败不再判为丢包（延迟才是关注点），并提取版本/人数/MOTD

const MC_MAX_PACKET = 4 * 1024 * 1024; // 大型整合包服务器的状态 JSON 可能有几百 KB

function mc_varint(int $v): string {
  $v &= 0xFFFFFFFF;
  $out = '';
  do {
    $b = $v & 0x7F;
    $v >>= 7;
    if ($v) $b |= 0x80;
    $out .= chr($b);
  } while ($v);
  return $out;
}

/** @return array{0:int,1:int}|null [值, 新偏移]；数据不完整时返回 null */
function mc_decode_varint(string $buf, int $off): ?array {
  $len = strlen($buf);
  $result = 0;
  for ($i = 0; $i < 5; $i++) {
    if ($off + $i >= $len) return null;
    $b = ord($buf[$off + $i]);
    $result |= ($b & 0x7F) << (7 * $i);
    if (!($b & 0x80)) {
      if ($result > 0x7FFFFFFF) $result -= 0x100000000;
      return [$result, $off + $i + 1];
    }
  }
  throw new RuntimeException('VarInt too big');
}

function mc_packet(int $id, string $payload): string {
  $d = mc_varint($id) . $payload;
  return mc_varint(strlen($d)) . $d;
}

function mc_wait($fp, bool $write, float $deadline): void {
  $left = $deadline - microtime(true);
  if ($left <= 0) throw new RuntimeException($write ? 'write timeout' : 'read timeout');
  $r = $write ? null : [$fp];
  $w = $write ? [$fp] : null;
  $e = null;
  $sec = (int)floor($left);
  $usec = (int)(($left - $sec) * 1e6);
  $n = @stream_select($r, $w, $e, $sec, $usec);
  if ($n === false) throw new RuntimeException('select failed');
  if ($n === 0) throw new RuntimeException($write ? 'write timeout' : 'read timeout');
}

function mc_write_all($fp, string $data, float $deadline): void {
  while ($data !== '') {
    mc_wait($fp, true, $deadline);
    $n = @fwrite($fp, $data);
    if ($n === false || $n === 0) throw new RuntimeException('write failed');
    $data = (string)substr($data, $n);
  }
}

/** 读取一个完整数据包 @return array{0:int,1:string} [packetId, payload] */
function mc_read_packet($fp, string &$buf, float $deadline): array {
  while (true) {
    $hdr = mc_decode_varint($buf, 0);
    if ($hdr !== null) {
      [$len, $off] = $hdr;
      if ($len < 1 || $len > MC_MAX_PACKET) throw new RuntimeException("bad packet length $len");
      if (strlen($buf) - $off >= $len) {
        $pkt = substr($buf, $off, $len);
        $buf = (string)substr($buf, $off + $len);
        $pid = mc_decode_varint($pkt, 0);
        if ($pid === null) throw new RuntimeException('truncated packet id');
        return [$pid[0], (string)substr($pkt, $pid[1])];
      }
    }
    mc_wait($fp, false, $deadline);
    $chunk = @fread($fp, 65536);
    if ($chunk === false || ($chunk === '' && feof($fp))) throw new RuntimeException('connection closed');
    $buf .= $chunk;
  }
}

/**
 * 解析 SRV + A 记录（每轮探测只做一次，避免把 DNS 抖动算进丢包）
 * @return array{addr:string,port:int,hs_host:string,hs_port:int,srv:?string}
 */
function mc_resolve(string $host, int $port, bool $useSrv = true): array {
  $hsHost = $host;
  $hsPort = $port;
  $srv = null;
  if ($useSrv && !filter_var($host, FILTER_VALIDATE_IP) && function_exists('dns_get_record')) {
    $recs = @dns_get_record('_minecraft._tcp.' . $host, DNS_SRV);
    if (is_array($recs) && $recs) {
      usort($recs, function ($a, $b) {
        return [$a['pri'] ?? 0, -($b['weight'] ?? 0)] <=> [$b['pri'] ?? 0, -($a['weight'] ?? 0)];
      });
      $hsHost = rtrim((string)$recs[0]['target'], '.');
      $hsPort = (int)$recs[0]['port'];
      $srv = $hsHost . ':' . $hsPort;
    }
  }
  $addr = $hsHost;
  if (!filter_var($hsHost, FILTER_VALIDATE_IP)) {
    $ips = @gethostbynamel($hsHost);
    if ($ips) $addr = $ips[0];
  }
  return ['addr' => $addr, 'port' => $hsPort, 'hs_host' => $hsHost, 'hs_port' => $hsPort, 'srv' => $srv];
}

/** MOTD 组件 → 纯文本 */
function mc_motd_text($desc): string {
  if (is_string($desc)) $t = $desc;
  elseif (is_array($desc)) {
    $t = isset($desc['text']) && is_string($desc['text']) ? $desc['text'] : '';
    if (isset($desc['extra']) && is_array($desc['extra'])) foreach ($desc['extra'] as $e) $t .= mc_motd_text($e);
  } else $t = '';
  return trim(preg_replace('/\x{00A7}./u', '', $t) ?? $t);
}

/**
 * MC Java status ping
 *
 * @param array|null $resolved mc_resolve() 的结果；为空则内部解析
 * @return array{ok:bool,latency_ms:?float,connect_ms:?float,json:?array,err:?string}
 */
function mc_status_ping(string $host, int $port, int $timeoutMs = 1500, int $protocolVersion = 760, ?array $resolved = null): array {
  $r = $resolved ?? mc_resolve($host, $port, true);
  $t0 = microtime(true);
  $deadline = $t0 + $timeoutMs / 1000;

  $addr = $r['addr'];
  if (strpos($addr, ':') !== false && $addr[0] !== '[') $addr = "[$addr]"; // IPv6
  $errno = 0; $errstr = '';
  $ctx = stream_context_create(['socket' => ['tcp_nodelay' => true]]);
  $fp = @stream_socket_client("tcp://{$addr}:{$r['port']}", $errno, $errstr, $timeoutMs / 1000, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) {
    return ['ok' => false, 'latency_ms' => null, 'connect_ms' => null, 'json' => null,
            'err' => 'connect fail: ' . ($errstr ?: 'timeout') . ($errno ? " ($errno)" : '')];
  }
  $connectMs = (microtime(true) - $t0) * 1000;

  try {
    stream_set_blocking($fp, false);
    if (function_exists('stream_set_read_buffer')) stream_set_read_buffer($fp, 0);
    $buf = '';

    // Handshake(0x00) + Status Request(0x00) 合并一次发送，少一次系统调用
    $hs = mc_varint($protocolVersion) . mc_varint(strlen($r['hs_host'])) . $r['hs_host']
        . pack('n', $r['hs_port']) . mc_varint(1);
    mc_write_all($fp, mc_packet(0x00, $hs) . mc_packet(0x00, ''), $deadline);

    [$pid, $payload] = mc_read_packet($fp, $buf, $deadline);
    if ($pid !== 0x00) throw new RuntimeException("unexpected status packetId=$pid");
    $json = null;
    $sl = mc_decode_varint($payload, 0);
    if ($sl !== null) {
      $j = json_decode((string)substr($payload, $sl[1], $sl[0]), true);
      if (is_array($j)) $json = $j;
    }

    // Ping(0x01) / Pong(0x01)：int64 大端时间戳（pack 'J' 即 64 位大端）
    $ping = pack('J', (int)floor(microtime(true) * 1000));
    $p0 = microtime(true);
    mc_write_all($fp, mc_packet(0x01, $ping), $deadline);
    [$pid2, $pong] = mc_read_packet($fp, $buf, $deadline);
    $p1 = microtime(true);
    if ($pid2 !== 0x01) throw new RuntimeException("unexpected pong packetId=$pid2");

    fclose($fp);
    return ['ok' => true, 'latency_ms' => ($p1 - $p0) * 1000, 'connect_ms' => $connectMs, 'json' => $json, 'err' => null];
  } catch (Throwable $e) {
    @fclose($fp);
    return ['ok' => false, 'latency_ms' => null, 'connect_ms' => $connectMs, 'json' => null, 'err' => $e->getMessage()];
  }
}

/** 从状态 JSON 提取展示用信息 */
function mc_status_info(?array $json): array {
  if (!$json) return [];
  return [
    'version'        => isset($json['version']['name']) ? u_sub((string)$json['version']['name'], 64) : null,
    'protocol'       => isset($json['version']['protocol']) ? (int)$json['version']['protocol'] : null,
    'players_online' => isset($json['players']['online']) ? (int)$json['players']['online'] : null,
    'players_max'    => isset($json['players']['max']) ? (int)$json['players']['max'] : null,
    'motd'           => isset($json['description']) ? u_sub(mc_motd_text($json['description']), 200) : null,
  ];
}
