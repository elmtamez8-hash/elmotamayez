<?php

use App\Modules\Courses\Exceptions\ContentLockedException;
use App\Shared\Middleware\EnsureCurrentWorkspace;
use App\Shared\Middleware\Idempotent;
use App\Shared\Middleware\RequireTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
    | Spec 010 — the broadcast authorisation endpoint.
    |
    | ⚠️ REGISTERED HERE RATHER THAN THROUGH `withRouting(channels:)`, WHICH TAKES
    | NO ATTRIBUTES. That form calls `Broadcast::routes(null)`, which defaults to
    | `['middleware' => ['web']]` — session auth, no rate limit, and no `/api`
    | prefix. Three consequences, each of which shows up as a silent 403 in a
    | browser and nowhere in a log:
    |
    |  - the frontend holds a Sanctum BEARER token in `localStorage`, so `web`
    |    alone identifies nobody and every private subscription is refused;
    |  - `NFR-014` wants a named limiter on this route, and it is authenticated
    |    and exposed — an inline `throttle:N,M` would share one bucket with every
    |    other inline limit on the platform;
    |  - the Next dev server rewrites `/api/*` to the API and nothing else, so
    |    without the prefix Echo's auth request leaves the origin and is a CORS
    |    failure rather than an authorisation one.
    |
    | ⚠️ AND `EnsureCurrentWorkspace` IS THE FOURTH, FOUND ONLY BY A LIVE SOCKET.
    | This list is NOT the `api` group — naming `auth:sanctum` here replaces the
    | group, it does not extend it — so the middleware that pushes the workspace
    | into spatie's `PermissionRegistrar` team id never ran. spatie is in team
    | mode, so with no team id a teacher holds NO ROLES: `hasPermissionTo(
    | 'chat.reply')` is false, `ConversationPolicy::view()` denies, and every
    | private subscription is answered `403` — while the same teacher reads the
    | very same thread over HTTP without trouble, because THAT route is in the
    | group. Measured against a running Reverb on 2026-08-23; no test could see
    | it, since the suite's `subscribeToChannel()` helper posts to this route with
    | the team id already set by `Sanctum::actingAs()` in the test's own process.
    */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api',
            'middleware' => ['auth:sanctum', EnsureCurrentWorkspace::class, 'throttle:broadcast-auth'],
        ],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies are configured in AppServiceProvider::boot(), not here.
        // This closure runs while the application is still being built, before
        // the config repository is bound — and env() is not an alternative,
        // because a cached config means .env is never loaded at all.
        $middleware->statefulApi();
        $middleware->alias([
            'workspace' => EnsureCurrentWorkspace::class,
            'idempotent' => Idempotent::class,
            'stateful' => EnsureFrontendRequestsAreStateful::class,
            // Named, and applied route by route — never to a group. See the
            // middleware's own docblock for why.
            '2fa.required' => RequireTwoFactor::class,
        ]);
        $middleware->appendToGroup('api', [
            EnsureCurrentWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Registered BEFORE the DomainException handler it extends: Laravel walks
        // these newest-first, and the general rule would otherwise answer 422 for
        // a case that has its own status and its own alternative.
        $exceptions->render(function (ContentLockedException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json([
                    'message' => $e->getMessage(),
                    'alternative' => $e->alternative,
                ], 423)
                : null;
        });

        // Actions signal a broken business rule with DomainException (attempt limit
        // reached, invitation expired, ...) — that's a 422, not a server error.
        $exceptions->render(function (DomainException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => $e->getMessage()], 422)
                : null;
        });
    })->create();
