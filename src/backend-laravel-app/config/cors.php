<?php

$configuredOrigins = array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://attendance.example.org')),
));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // API clients are served from this site.  Do not use a wildcard here: it
    // would allow a third-party web page to issue authenticated browser calls.
    'allowed_origins' => array_values($configuredOrigins),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,
];
