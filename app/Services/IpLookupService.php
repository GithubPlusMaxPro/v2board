<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IpLookupService
{
    public function lookup(string $ip, bool $browser = false): array
    {
        $ip = UserOnlineService::normalizeIp($ip);
        if ($ip === null) abort(422, 'IP 格式无效');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['ip' => $ip, 'status' => 'local', 'country' => '内网或保留地址'];
        }
        if (!config('ip_lookup.enabled')) return ['ip' => $ip, 'status' => 'disabled'];
        $provider = config('ip_lookup.provider', 'ipsb');
        $providers = ['ipsb' => 'IP.SB', 'ipwhois' => 'IPWhois', 'geojs' => 'GeoJS'];
        if (!isset($providers[$provider])) $provider = 'ipsb';
        if ($browser && $provider === 'ipsb') {
            return ['ip' => $ip, 'status' => 'browser', 'provider' => 'IP.SB',
                'cache_seconds' => (int)config('ip_lookup.cache_seconds', 86400),
                'failure_cache_seconds' => (int)config('ip_lookup.failure_cache_seconds', 300),
                'timeout' => (int)config('ip_lookup.timeout', 3)];
        }
        $key = 'IP_LOOKUP_V2_' . $provider . '_' . hash('sha256', $ip);
        $cached = Cache::get($key);
        if (is_array($cached)) return $cached;
        $result = ['ip' => $ip, 'status' => 'unavailable', 'provider' => $providers[$provider]];
        $cooldownKey = 'IP_LOOKUP_COOLDOWN_' . $provider;
        if (Cache::get($cooldownKey)) return $result;
        $endpoints = ['ipsb' => 'https://api.ip.sb/geoip/', 'ipwhois' => 'https://ipwho.is/',
            'geojs' => 'https://get.geojs.io/v1/ip/geo/'];
        $url = $endpoints[$provider] . rawurlencode($ip) . ($provider === 'geojs' ? '.json' : '');
        try {
            $response = Http::acceptJson()->withHeaders(['User-Agent' => 'V2Board-IPLookup/1.0'])->timeout(config('ip_lookup.timeout', 3))
                ->withOptions(['allow_redirects' => false, 'connect_timeout' => 2])->get($url);
            if ($response->status() === 429) {
                $retryAfter = max(60, min(86400, (int)($response->header('Retry-After') ?: 300)));
                Cache::put($cooldownKey, true, $retryAfter);
            }
            $data = $response->json();
            $success = is_array($data) && ($provider !== 'ipwhois' || ($data['success'] ?? false) === true);
            if ($response->successful() && $success && UserOnlineService::normalizeIp($data['ip'] ?? null) === $ip) {
                $result['status'] = 'ok';
                if ($provider === 'ipwhois') {
                    $connection = is_array($data['connection'] ?? null) ? $data['connection'] : [];
                    $data['organization_name'] = $connection['isp'] ?? $connection['org'] ?? '';
                    $data['asn'] = $connection['asn'] ?? '';
                } elseif ($provider === 'ipsb') {
                    $data['organization_name'] = $data['isp'] ?? $data['organization'] ?? $data['asn_organization'] ?? '';
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
