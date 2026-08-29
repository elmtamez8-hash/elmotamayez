<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The public site's own origin
    |--------------------------------------------------------------------------
    |
    | Absolute URLs for the search engine: the blog lives in Next, not here, so
    | `APP_URL` (the API) is the wrong answer and `url()` would produce a link to
    | a host with no page on it. Derived from `FRONTEND_URL`, which the CORS
    | allowlist and the payment return URLs already read — a second env var for
    | one host is two values that disagree the first time one of them moves.
    |
    */

    'site_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    /*
    |--------------------------------------------------------------------------
    | IndexNow — FR-037, «النشر أو التحديث يجب أن يُبلَّغ به محرك البحث آلياً»
    |--------------------------------------------------------------------------
    |
    | ⚠️ THE OLD SITEMAP PING IS GONE, AND WRITING ONE WOULD HAVE SHIPPED A JOB
    | THAT DOES NOTHING. Google removed `/ping?sitemap=` in January 2024 and Bing
    | had already routed its own to IndexNow — a POST to either address today is a
    | 404 or a redirect, logged as a warning nobody reads, satisfying FR-037 on
    | paper and notifying no search engine at all.
    |
    | ⚠️ AND THE KEY IS PUBLIC BY PROTOCOL, WHICH IS NOT THE SAME AS BEING A
    | SECRET WE MAY COMMIT. Ownership is proved by serving the key as a text file
    | at the site root — so it is readable by anyone who asks for it — but it is
    | still the credential that authorises submissions for this host, and the
    | repository holds no credentials of any kind. It is an environment value, and
    | the key FILE is an operator step at deploy time (`{key}.txt` in the
    | frontend's `public/`).
    |
    | ⚠️ SO AN UNCONFIGURED DEPLOYMENT PINGS NOTHING, SILENTLY AND DELIBERATELY.
    | Submitting under a key whose file is not being served earns a `403` on every
    | publish for as long as it stays that way; a no-op is the honest behaviour
    | and `docs/README.md` records that FR-037 is implemented-and-off until the
    | file is in place.
    |
    */

    'indexnow' => [
        // The shared endpoint. Every participating engine (Bing, Yandex, Seznam,
        // Naver) forwards to the others, so one submission reaches all of them.
        'endpoint' => env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),

        // 8–128 characters of `[a-zA-Z0-9-]`, per the protocol.
        'key' => env('INDEXNOW_KEY'),

        // Where the key file is served. Left unset it defaults to `{site}/{key}.txt`
        // — the root, which is the only placement that validates URLs anywhere on
        // the site: the protocol scopes a key to its own path hierarchy, so a key
        // under `/.well-known/` may only ever submit `/.well-known/` URLs.
        'key_location' => env('INDEXNOW_KEY_LOCATION'),
    ],

];
