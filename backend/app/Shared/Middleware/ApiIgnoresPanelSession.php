<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A public API route answers as a GUEST, whatever `/admin` session the browser carries.
 *
 * `auth:sanctum` calls `shouldUse('sanctum')`; a route without it keeps the
 * default `web` guard, and `statefulApi()` starts the session — so a staff
 * member signed into `/admin` was that panel account on every PUBLIC endpoint:
 * `WorkspaceContext` resolved the panel's workspace (public lists scoped to it)
 * and `RefuseStaleWorkspace` compared it with the SPA account's `X-Workspace` —
 * a 409 «workspace changed» on `/signup/grade-levels` (2026-10-22).
 *
 * Not `shouldUse('sanctum')`: that makes a signed-in teacher's bearer scope the
 * public marketplace to their own workspace. A public route that wants the
 * caller asks `$request->user('sanctum')` (BreachReportController) — unchanged.
 * Authenticate runs after or before this; either way its `shouldUse` wins.
 */
final class ApiIgnoresPanelSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::getDefaultDriver() === 'web') {
            Auth::shouldUse('api-public');
        }

        return $next($request);
    }
}
