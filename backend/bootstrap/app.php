<?php

use App\Shared\Middleware\EnsureCurrentWorkspace;
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
        $middleware->statefulApi();
        $middleware->alias([
            'workspace' => EnsureCurrentWorkspace::class,
            'stateful' => EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->appendToGroup('api', [
            EnsureCurrentWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Actions signal a broken business rule with DomainException (attempt limit
        // reached, invitation expired, ...) — that's a 422, not a server error.
        $exceptions->render(function (DomainException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => $e->getMessage()], 422)
                : null;
        });
    })->create();
