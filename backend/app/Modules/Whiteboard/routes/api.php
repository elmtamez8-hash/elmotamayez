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
use App\Modules\Whiteboard\Http\Controllers\BoardFileController;
use App\Modules\Whiteboard\Http\Controllers\BoardLessonExportController;
use App\Modules\Whiteboard\Http\Controllers\BoardLibraryController;
use App\Modules\Whiteboard\Http\Controllers\BoardLockController;
use App\Modules\Whiteboard\Http\Controllers\BoardPageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/boards', [BoardController::class, 'index']);
    Route::post('/boards', [BoardController::class, 'store'])->middleware('throttle:authoring');
    Route::get('/boards/{board}', [BoardController::class, 'show']);
    Route::patch('/boards/{board}', [BoardController::class, 'update'])->middleware('throttle:authoring');
    Route::post('/boards/{board}/duplicate', [BoardController::class, 'duplicate'])->middleware('throttle:authoring');
    Route::delete('/boards/{board}', [BoardController::class, 'destroy'])->middleware(['2fa.required', 'throttle:authoring']);

    // The edit lock and autosave beat every few seconds per tab — their own limiter.
    Route::middleware('throttle:whiteboard-autosave')->group(function (): void {
        Route::post('/boards/{board}/lock', [BoardLockController::class, 'store']);
        Route::delete('/boards/{board}/lock', [BoardLockController::class, 'destroy']);
        Route::post('/boards/{board}/lock/take', [BoardLockController::class, 'take']);
        Route::put('/boards/{board}/pages/{page}/scene', [BoardPageController::class, 'scene']);
        // Before the {page} routes: `order` is not a page uuid.
        Route::post('/boards/{board}/pages', [BoardPageController::class, 'store']);
        Route::put('/boards/{board}/pages/order', [BoardPageController::class, 'order']);
        Route::delete('/boards/{board}/pages/{page}', [BoardPageController::class, 'destroy']);
    });

    // Pictures. Opening a board fetches every one of them at once, so reading has
    // its own generous limiter and drops the `api` group's floor (as chat media does).
    // «إرفاق بمواد الدرس» (story 5): the replacement deletes the old attachment,
    // so it alone asks two-factor, as deleting any attachment does (D2).
    Route::post('/boards/{board}/lesson-exports', [BoardLessonExportController::class, 'store'])->middleware('throttle:authoring');
    Route::put('/boards/{board}/lesson-exports/{export}', [BoardLessonExportController::class, 'update'])->middleware(['2fa.required', 'throttle:authoring']);
    Route::post('/boards/{board}/files', [BoardFileController::class, 'store'])->middleware('throttle:upload');
    Route::post('/boards/{board}/files/{file}/complete', [BoardFileController::class, 'complete'])->middleware('throttle:upload');
    Route::get('/boards/{board}/files/{file}', [BoardFileController::class, 'show'])
        ->middleware('throttle:whiteboard-files')
        ->withoutMiddleware('throttle:api');

    // The academy's shared board library — its doors are in BoardLibraryTest.
    Route::get('/board-library', [BoardLibraryController::class, 'index']);
    Route::post('/board-library', [BoardLibraryController::class, 'store'])->middleware('throttle:authoring');
    Route::delete('/board-library/{item}', [BoardLibraryController::class, 'destroy'])->middleware('throttle:authoring');
});
