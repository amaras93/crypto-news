<?php

return [
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'additional_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),
    'hsts_enabled' => (bool) env('HSTS_ENABLED', false),
    'hsts_max_age' => max(300, min(31536000, (int) env('HSTS_MAX_AGE', 31536000))),
];
