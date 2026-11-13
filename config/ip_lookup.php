<?php

return [
    'enabled' => env('IP_LOOKUP_ENABLED', true),
    'provider' => env('IP_LOOKUP_PROVIDER', 'ipsb'),
    'cache_seconds' => 86400,
    'failure_cache_seconds' => 300,
    'timeout' => 3,
];
