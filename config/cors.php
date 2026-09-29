<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Explicit allow-list — never a wildcard combined with credentials.
    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Headers the browser client is allowed to read (request ids + rate limits).
    'exposed_headers' => ['X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'],

    'max_age' => 3600,

    // API authentication uses the Authorization header, not browser cookies.
    'supports_credentials' => false,

];
