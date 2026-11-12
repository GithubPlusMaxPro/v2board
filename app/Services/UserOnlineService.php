<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserOnlineService
{
    const ACTIVE_SECONDS = 100;

    public static function normalizeIp($value): ?string
    {
        if (!is_string($value)) return null;
        $ip = explode('_', $value, 2)[0];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;
        $packed = inet_pton($ip);
        // Nodes may report an IPv4 peer as ::ffff:a.b.c.d; treat both forms as one IP.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $packed = substr($packed, 12);
        return inet_ntop($packed);
    }

    public static function normalizeIps(array $ips): array
    {
        $normalized = [];
        foreach ($ips as $value) {
            $ip = self::normalizeIp($value);
            if ($ip !== null) $normalized[$ip] = true;
        }
        return array_keys($normalized);
    }

    public function current(int $userId, ?int $now = null): array
    {
        $now = $now ?? time();
        $cache = Cache::get('ALIVE_IP_USER_' . $userId, []);
        return $this->snapshot(is_array($cache) ? $cache : [], $now, true);
    }

    public function snapshot(array $cache, int $now, bool $resolveNames = false): array
    {
        $items = []; $uniqueIps = []; $nodes = [];
        foreach (is_array($cache) ? $cache : [] as $key => $value) {
            if (!is_array($value) || !isset($value['aliveips'], $value['lastupdateAt']) || !is_array($value['aliveips'])) continue;
            if ($now - (int)$value['lastupdateAt'] > self::ACTIVE_SECONDS) continue;
            if (!preg_match('/^(shadowsocks|vmess|vless|trojan|hysteria|tuic|anytls|v2node)([1-9][0-9]*)$/', (string)$key, $match)) continue;
            $ips = self::normalizeIps($value['aliveips']);
            if (!$ips) continue;
            $name = $value['server_name'] ?? null;
            if ($name === null && $resolveNames) $name = DB::table('v2_server_' . $match[1])->where('id', (int)$match[2])->value('name');
            foreach ($ips as $ip) {
                $items[] = ['ip' => $ip, 'server_type' => $match[1], 'server_id' => (int)$match[2],
                    'server_name' => $name ?: '未命名或已删除节点', 'last_seen' => (int)$value['lastupdateAt']];
                $uniqueIps[$ip] = true; $nodes[$key] = true;
            }
        }
        return ['as_of' => $now, 'window_seconds' => self::ACTIVE_SECONDS, 'unique_ip_count' => count($uniqueIps),
            'node_count' => count($nodes), 'items' => $items];
    }
}
