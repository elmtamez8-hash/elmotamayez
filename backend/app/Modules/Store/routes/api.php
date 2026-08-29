<?php

declare(strict_types=1);

use App\Modules\Store\Http\Controllers\ShipmentController;
use App\Modules\Store\Http\Controllers\StoreItemController;
use App\Modules\Store\Http\Controllers\StorePurchaseController;
use Illuminate\Support\Facades\Route;

/*
| Store routes — spec 011 · US1.
|
| Auto-prefixed `/api/v1` with the `api` middleware group by
| `App\Shared\Modules\Module::registerRoutes()`.
|
| ⚠️ EVERY WRITE CARRIES `throttle:store-write`, AND NEVER `throttle:auth`. That
| limiter's second key is `'email:'.$request->input('email')` — a purchase
| carries no email field, so the key collapses to a constant and every write on
| the platform shares one five-per-minute counter. `AppServiceProvider` explains
| that failure in prose as the reason the `billing` limiter exists.
|
| ⚠️ AND A STUDENT ROUTE NEVER BINDS A MODEL IMPLICITLY. `StoreOrder` carries
| `BelongsToWorkspace`, which protects NOTHING on a student's path: a student is
| a member of no workspace, so `WorkspaceContext::id()` is null and
| `WorkspaceScope::apply()` adds no condition — the uuid would resolve any
| buyer's order. The teacher's routes DO bind, safely, because the reader is a
| member there and a foreign uuid 404s before any policy runs.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    // ── The teacher ─────────────────────────────────────────────────────────
    Route::get('/store/items', [StoreItemController::class, 'index']);
    Route::post('/store/items', [StoreItemController::class, 'store'])
        ->middleware('throttle:store-write');
    Route::put('/store/items/{item}', [StoreItemController::class, 'update'])
        ->middleware('throttle:store-write');

    Route::get('/store/shipments', [ShipmentController::class, 'index']);
    Route::patch('/store/shipments/{shipment}', [ShipmentController::class, 'update'])
        ->middleware('throttle:store-write');

    // ── The buyer ───────────────────────────────────────────────────────────
    Route::get('/store/catalogue', [StorePurchaseController::class, 'catalogue']);
    Route::get('/store/purchases', [StorePurchaseController::class, 'purchases']);

    // `idempotent` as well as the limiter: a double-tapped buy button on a slow
    // connection is one purchase, not two orders waiting for two transfers.
    Route::post('/store/purchases', [StorePurchaseController::class, 'store'])
        ->middleware(['throttle:store-write', 'idempotent']);

    // `{purchase}` is a STRING, resolved inside the Action against
    // `buyer_user_id`. See the second warning above.
    Route::post('/store/purchases/{purchase}/open', [StorePurchaseController::class, 'open'])
        ->middleware('throttle:store-write');
    Route::post('/store/purchases/{purchase}/refund', [StorePurchaseController::class, 'refund'])
        ->middleware(['throttle:store-write', 'idempotent']);
});
