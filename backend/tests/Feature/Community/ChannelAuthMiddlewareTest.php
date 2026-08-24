<?php

declare(strict_types=1);

use App\Shared\Middleware\EnsureCurrentWorkspace;
use Illuminate\Support\Facades\Route;

/*
| Spec 010 · `ح-٤/أ` — the guard for the defect the first real socket found.
|
| ⚠️ THIS ASSERTS THE ROUTE'S MIDDLEWARE, NOT ITS BEHAVIOUR, AND THAT IS THE ONLY
| SHAPE THAT WORKS. `/api/broadcasting/auth` is registered through
| `withBroadcasting()`, whose middleware list REPLACES the `api` group rather than
| extending it — so `EnsureCurrentWorkspace` was absent, spatie's team id was never
| pushed, and in team mode that means a teacher holds NO ROLES at all:
| `hasPermissionTo('chat.reply')` false, `ConversationPolicy::view()` denied, `403`
| on every private subscription. The same teacher read the same thread over HTTP
| without trouble, because THAT route is inside the group — which is why nothing on
| the screen pointed here.
|
| ⚠️ AND NO REQUEST-BASED TEST CAN SEE IT. `subscribeToChannel()` posts to this
| route from a process where `Sanctum::actingAs()` has ALREADY set the team id, so
| the request passes with or without the middleware: the suite proves the channel
| callback's logic and never the request's own resolution. Rewriting this as «post
| and expect 200» would be green today and green again the day somebody trims the
| list — the exact vacuous pass this file exists to replace.
|
| Measured live on 2026-08-23: `BEFORE team id: hasPermission=false` ·
| `AFTER: true`.
*/

it('resolves the workspace on the channel authorisation route', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($candidate): bool => $candidate->uri() === 'api/broadcasting/auth');

    expect($route)->not->toBeNull('مسار تفويض القنوات غير مسجَّل إطلاقاً.');

    expect($route->gatherMiddleware())
        ->toContain(EnsureCurrentWorkspace::class);
});

it('still authenticates and rate-limits that route', function (): void {
    // The two the original registration DID carry. Kept beside the new one so a
    // rewrite of the list has to drop them visibly rather than by omission.
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($candidate): bool => $candidate->uri() === 'api/broadcasting/auth');

    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain('auth:sanctum')
        ->and($middleware)->toContain('throttle:broadcast-auth');
});
