<?php

return [
    'enabled' => env('IP_LOOKUP_ENABLED', true),
    'provider' => env('IP_LOOKUP_PROVIDER', 'ip2region'),
    'database_v4' => env('IP2REGION_IPV4_PATH', database_path('ip2region/ip2region_v4.xdb')),
    'database_v6' => env('IP2REGION_IPV6_PATH', database_path('ip2region/ip2region_v6.xdb')),
    'cache_seconds' => 86400,
    'failure_cache_seconds' => 300,
    'timeout' => 3,
];
