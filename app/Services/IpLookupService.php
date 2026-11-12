<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
        $provider = config('ip_lookup.provider', 'ipwhois');
        if (!in_array($provider, ['ipwhois', 'geojs'], true)) $provider = 'ipwhois';
        $key = 'IP_LOOKUP_V2_' . $provider . '_' . hash('sha256', $ip);
        $cached = Cache::get($key);
        if (is_array($cached)) return $cached;
        $result = ['ip' => $ip, 'status' => 'unavailable', 'provider' => $provider === 'ipwhois' ? 'IPWhois' : 'GeoJS'];
        $cooldownKey = 'IP_LOOKUP_COOLDOWN_' . $provider;
        if (Cache::get($cooldownKey)) return $result;
        $url = $provider === 'ipwhois' ? 'https://ipwho.is/' . rawurlencode($ip)
            : 'https://get.geojs.io/v1/ip/geo/' . rawurlencode($ip) . '.json';
        try {
            $response = Http::acceptJson()->timeout(config('ip_lookup.timeout', 3))
                ->withOptions(['allow_redirects' => false, 'connect_timeout' => 2])->get($url);
            if ($response->status() === 429) {
                $retryAfter = max(60, min(86400, (int)($response->header('Retry-After') ?: 300)));
                Cache::put($cooldownKey, true, $retryAfter);
            }
            $data = $response->json();
            $success = is_array($data) && ($provider === 'geojs' || ($data['success'] ?? false) === true);
            if ($response->successful() && $success && UserOnlineService::normalizeIp($data['ip'] ?? null) === $ip) {
                $result['status'] = 'ok';
                if ($provider === 'ipwhois') {
                    $connection = is_array($data['connection'] ?? null) ? $data['connection'] : [];
                    $data['organization_name'] = $connection['isp'] ?? $connection['org'] ?? '';
                    $data['asn'] = $connection['asn'] ?? '';
                }
                foreach (['country', 'country_code', 'region', 'city', 'organization_name', 'asn'] as $field) {
                    $result[$field] = isset($data[$field]) && is_scalar($data[$field]) ? mb_substr((string)$data[$field], 0, 255) : '';
                }
            }
        } catch (\Throwable $e) {
            // IP metadata is optional and must never break online or traffic queries.
        }
        Cache::put($key, $result, $result['status'] === 'ok' ? config('ip_lookup.cache_seconds', 86400) : config('ip_lookup.failure_cache_seconds', 300));
        return $result;
    }
}
