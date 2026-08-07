<?php

declare(strict_types=1);

use App\Modules\Settlement\Http\Controllers\RateChangeController;
use App\Modules\Settlement\Http\Controllers\StatementController;
use App\Modules\Settlement\Http\Controllers\TeachingUnitController;
use Illuminate\Support\Facades\Route;

/*
 * Settlement routes.
 *
 * `App\Shared\Modules\Module` loads this file with the `/api/v1` prefix and the
 * `api` middleware group already applied — do not repeat either here.
 *
 * Every write carries the NAMED limiter `settlement-write`. An inline
 * `throttle:N,M` is banned: ThrottleRequests hashes only `domain|ip` with no
 * route in the key, so every inline limit in the product shares one counter and
 * the strictest one wins.
 */

Route::middleware('auth:sanctum')->group(function (): void {
    // No `teacher` parameter on any of these, on purpose (FR-019): the profile
    // comes from the bearer token, so "may I read this other teacher?" is not a
    // question the code has to keep answering correctly.
    Route::get('/settlement/statement', [StatementController::class, 'show']);
    Route::get('/settlement/statement/export', [StatementController::class, 'export']);

    Route::get('/settlement/units', [TeachingUnitController::class, 'index']);
    Route::get('/settlement/rates', [RateChangeController::class, 'rates']);
    Route::get('/settlement/rate-requests', [RateChangeController::class, 'index']);

    Route::middleware('throttle:settlement-write')->group(function (): void {
        // The teacher asks. Nothing takes effect here.
        Route::post('/settlement/rate-requests', [RateChangeController::class, 'store']);

        // The platform decides. Approval is the only thing anywhere that writes a
        // settlement_rates row, which is what makes "no rate without approval" a
        // property of the code rather than a rule to remember.
        Route::post('/admin/settlement/rate-requests/{rateRequest}/approve', [RateChangeController::class, 'approve']);
        Route::post('/admin/settlement/rate-requests/{rateRequest}/reject', [RateChangeController::class, 'reject']);

        // The correction. An explicit administrative act with an author and a
        // reason — never an attendance edit (spec Q7).
        Route::post('/admin/settlement/units/{unit}/reverse', [TeachingUnitController::class, 'reverse']);
    });
});
