<?php

return [
    'enabled' => env('IP_LOOKUP_ENABLED', true),
    'cache_seconds' => 604800,
    'failure_cache_seconds' => 300,
    'timeout' => 3,
];
