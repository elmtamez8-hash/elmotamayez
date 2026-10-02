<?php

declare(strict_types=1);

/*
| Whiteboard routes — spec 039.
|
| Auto-prefixed `/api/v1` with the `api` middleware group by
| `App\Shared\Modules\Module::registerRoutes()`.
|
| ⚠️ EVERY NESTED UUID IS RESOLVED THROUGH THE BOARD, never by implicit binding on
| its own: no module here uses `scopeBindings()`, and a page or file uuid bound
| alone would resolve another board's row in the same workspace.
|
| ⚠️ EVERY ROUTE HERE HAS A ROW IN `BoardIsolationTest`, which counts them — a new
| route without a row turns that test red.
*/

use App\Modules\Whiteboard\Http\Controllers\BoardController;
use App\Modules\Whiteboard\Http\Controllers\BoardLockController;
use App\Modules\Whiteboard\Http\Controllers\BoardPageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/boards', [BoardController::class, 'index']);
    Route::post('/boards', [BoardController::class, 'store'])->middleware('throttle:authoring');
    Route::get('/boards/{board}', [BoardController::class, 'show']);
    Route::patch('/boards/{board}', [BoardController::class, 'update'])->middleware('throttle:authoring');

    // The edit lock and autosave beat every few seconds per tab — their own limiter.
    Route::middleware('throttle:whiteboard-autosave')->group(function (): void {
        Route::post('/boards/{board}/lock', [BoardLockController::class, 'store']);
        Route::delete('/boards/{board}/lock', [BoardLockController::class, 'destroy']);
        Route::post('/boards/{board}/lock/take', [BoardLockController::class, 'take']);
        Route::put('/boards/{board}/pages/{page}/scene', [BoardPageController::class, 'scene']);
    });
});
