<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| Store routes — spec 011 · US1.
|
| Auto-prefixed `/api/v1` with the `api` middleware group by
| `App\Shared\Modules\Module::registerRoutes()`.
|
| ⚠️ EVERY WRITE HERE CARRIES `throttle:store-write`, AND NEVER `throttle:auth`.
| That limiter's second key is `'email:'.$request->input('email')` — a purchase
| carries no email field, so the key collapses to a constant and every write on
| the platform shares one five-per-minute counter. `AppServiceProvider` explains
| that failure in prose as the reason the `billing` limiter exists.
|
| ⚠️ AND A STUDENT ROUTE NEVER BINDS A MODEL IMPLICITLY. `StoreOrder` carries
| `BelongsToWorkspace`, which protects NOTHING on a student's path: a student is
| a member of no workspace, so `WorkspaceContext::id()` is null and
| `WorkspaceScope::apply()` adds no condition — the uuid would resolve any
| buyer's order. Ownership is resolved inside the Action.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    // Filled in by T044 (teacher) and T044 (student) — the module ships its
    // scaffold first so `phpstan.neon` and the isolation guards can be wired
    // before any table exists.
});
