<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configured for local WiFi/LAN deployment via Docker.
    | - Exact origins: localhost dev servers
    | - Patterns: any device on 192.168.x.x or 10.x.x.x networks
    |
    | This allows admin panels, kiosks, and mobile devices on the same
    | WiFi to access the API, while blocking external websites from
    | hijacking authenticated sessions (CSRF via CORS).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Exact origins (local development)
    'allowed_origins' => [
        'http://localhost:5173',      // Vite dev server
        'http://localhost:3000',      // Alternate dev server
        'http://127.0.0.1:5173',     // Vite via loopback
        'http://127.0.0.1:8000',     // Laravel dev server
    ],

    // Regex patterns for LAN/WiFi access (any port)
    'allowed_origins_patterns' => [
        '#^https?://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',  // 192.168.x.x (home/WiFi)
        '#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#', // 10.x.x.x (school/enterprise)
        '#^https?://172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}(:\d+)?$#', // 172.16-31.x.x (Docker)
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
