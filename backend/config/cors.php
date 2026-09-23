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

    /*
    | ⚠️ `admin/*` AND `horizon/*` ARE DELIBERATELY ABSENT. Both are session
    | panels opened by NAVIGATING to them (`lib/admin-panel.ts` fetches a ticket
    | from `/api` and then sets `window.location`); nothing in `frontend/src`
    | calls either with `fetch`. Listing them only made two cookie-authenticated
    | surfaces answerable cross-origin with credentials — to every origin below.
    | (`/admin/billing/...` in `lib/billing.ts` is `/api/v1/admin/...`, under
    | `api/*`.)
    */
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    /*
    | ⚠️ THE LOCALHOST ORIGINS EXIST FOR `APP_ENV=local` ONLY. With
    | `supports_credentials` on, every origin here may make credentialed calls,
    | and in production the site is same-origin (nginx serves Next and `/api`
    | from one host), so it needs no cross-origin entry at all — while a page on
    | the visitor's own `localhost:3000` is anybody's. Production allows
    | `FRONTEND_URL` if it is set (a split-host deployment) and nothing else.
    | An unset APP_ENV counts as production, so a forgotten variable fails closed.
    */
    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL'),
        ...(env('APP_ENV', 'production') === 'local'
            ? ['http://localhost:3000', 'http://127.0.0.1:3000']
            : []),
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
