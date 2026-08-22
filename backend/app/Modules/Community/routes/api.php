<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| Spec 010 — the Community module's API surface.
|
| Auto-loaded by `App\Shared\Modules\Module::registerRoutes()`, which prefixes
| `/api/v1` and applies the `api` middleware group (and with it
| `EnsureCurrentWorkspace`). Never repeat either here.
|
| ⚠️ NO ROUTE IN THIS FILE BINDS A MODEL IMPLICITLY. A student is a member of no
| workspace at all, so `WorkspaceContext::id()` is null for them and
| `WorkspaceScope` adds no condition — an implicit `{conversation}` or
| `{message}` binding resolves ANY workspace's row before a single policy runs.
| Every identifier is a bare `uuid` resolved INSIDE the Action, after the
| membership check. The `RedeemReward` pattern from 009, literally.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    //
});
