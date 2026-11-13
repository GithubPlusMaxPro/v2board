<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class IpLookupService
{
    public function lookup(string $ip): array
    {
        $ip = UserOnlineService::normalizeIp($ip);
        if ($ip === null) abort(422, 'IP 格式无效');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['ip' => $ip, 'status' => 'local', 'country' => '内网或保留地址'];
        }
        if (!config('ip_lookup.enabled')) return ['ip' => $ip, 'status' => 'disabled'];
        return $this->lookupLocal($ip);
    }

    private function lookupLocal(string $ip): array
    {
        $result = ['ip' => $ip, 'status' => 'unavailable', 'provider' => 'ip2region'];
        $ipv6 = strpos($ip, ':') !== false;
        $file = $ipv6 ? 'ip2region_v6.xdb' : 'ip2region_v4.xdb';
        $path = config('ip_lookup.' . ($ipv6 ? 'database_v6' : 'database_v4'), database_path('ip2region/' . $file));
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) return $result;
        $version = (realpath($path) ?: $path) . ':' . filemtime($path) . ':' . filesize($path);
        $key = 'IP_LOOKUP_IP2REGION_V1_' . hash('sha256', $version . ':' . $ip);
        $cached = Cache::get($key);
        if (is_array($cached)) return $cached;
        $searcher = null;
        try {
            require_once base_path('library/Ip2Region/Searcher.class.php');
            $ipVersion = $ipv6 ? \ip2region\xdb\IPv6::default() : \ip2region\xdb\IPv4::default();
            $searcher = \ip2region\xdb\Searcher::newWithFileOnly($ipVersion, $path);
            $region = $searcher->search($ip);
            if ($region !== '') {
                $parts = explode('|', $region);
                if (count($parts) === 5) {
                    foreach (['country', 'region', 'city', 'organization_name', 'country_code'] as $index => $field) {
                        $value = trim($parts[$index]);
                        $result[$field] = $value === '0' ? '' : mb_substr($value, 0, 255);
                    }
                    $result['asn'] = '';
                    if ($result['country'] !== '' || $result['region'] !== '' || $result['city'] !== '') $result['status'] = 'ok';
                }
            }
        } catch (\Throwable $e) {
            // A missing or unreadable location must not interrupt online or traffic records.
        } finally {
            if ($searcher !== null) $searcher->close();
        }
        Cache::put($key, $result, $result['status'] === 'ok' ? config('ip_lookup.cache_seconds', 86400) : config('ip_lookup.failure_cache_seconds', 300));
        return $result;
    }
}
