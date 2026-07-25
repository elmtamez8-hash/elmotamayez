<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Controls which origins may make cross-origin requests to the API.
    | The frontend (Next.js) runs on :3000 and the API on :8000,
    | so CORS must allow the frontend origin in development.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'admin/*',
        'horizon/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:3000'),
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
