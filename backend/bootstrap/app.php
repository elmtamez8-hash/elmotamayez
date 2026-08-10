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
