<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Allows the browser-based dashboard (and any web client) to call the API
| from a different origin during local dev and in production. Native mobile
| apps and Postman are not subject to CORS, so this only affects browsers.
|
| Set FRONTEND_URL in .env to the dashboard origin per environment, e.g.
|   FRONTEND_URL=http://localhost:5173   (Vite dev server)
|   FRONTEND_URL=https://dashboard.example.ly   (production)
|
*/

return [

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL'),
        'http://localhost:5173', // Vite (dashboard dev)
        'http://localhost:3000', // common alt dev port
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Required if the dashboard uses Sanctum SPA cookie auth; harmless for
    // bearer-token auth. With this true, allowed_origins must be explicit
    // (no '*'), which is already the case above.
    'supports_credentials' => true,

];
