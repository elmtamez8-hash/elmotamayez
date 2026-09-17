<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    /*
    | ⛔ **EMPTY ON PURPOSE, AND THE DEVICE LIMIT IS THE WHOLE REASON.**
    |
    | Laravel's default here is `['web']`, and `Guard::__invoke()` (vendor,
    | `laravel/sanctum/src/Guard.php:31`) loops this array FIRST — reaching the
    | bearer token only if no guard in it answered. When the session answers it
    | returns `$user->withAccessToken(new TransientToken)`: an identity with **no
    | `personal_access_tokens` row behind it at all**.
    |
    | And deleting that row IS the enforcement. `TerminateAuthSession:29` is the
    | only instrument the product has for evicting a device, so for anyone holding
    | an authenticated session it deletes nothing: `auth_sessions.status` is written
    | `ended`, the owner is told the device is out, **and the device carries on**.
    | No error is logged anywhere, because nothing failed.
    |
    | ⚠️ **This was not theoretical — it was measured on production 2026-09-17**:
    | `POST /api/v1/auth/logout` with **no `Authorization` header whatsoever**
    | answered `204`. The cookie alone authenticated; `localStorage.auth_token` sat
    | there unread. And it had already cost a 500 the day before — see
    | `tests/Feature/Identity/LogoutSurvivesCookieAuthTest.php`, which fixed the
    | crash that seam produced without touching the seam itself.
    |
    | ⚠️ **AND IT CANNOT BE CLOSED FROM `.env`.** `stateful` above is derived from
    | `APP_URL` via `Sanctum::currentApplicationUrlWithPort()`, and production's
    | `docker/.env` carries no `SANCTUM_STATEFUL_DOMAINS` at all — checked. So the
    | session pipeline has been on since the first boot and stays on; this line is
    | what stops it being an ANSWER to «who are you» on the API.
    |
    | What is deliberately NOT changed: `/admin` keeps its own web session (Filament
    | authenticates against the `web` guard directly, never through `auth:sanctum`),
    | and so does the panel handoff bridge. This line is about the API alone.
    |
    | The residual gap is written down rather than pretended away: a panel session
    | is still absent from `auth_sessions`, so «أجهزتي» does not list it. Harmless
    | now that it authenticates nothing on the API — and it is `specs/037-…`'s
    | story 2, deferred by the owner on 2026-09-17.
    |
    | **How it is caught**: put `['web']` back and
    | `tests/Feature/Identity/ApiIsBearerOnlyTest.php` fails — measured, two of its
    | three cases, with the bearer-token control staying green.
    */
    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
