<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Http\Controllers\BookingController;
use App\Modules\LiveSessions\Http\Controllers\ClassSessionController;
use App\Modules\LiveSessions\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
 * Routes for the LiveSessions module.
 *
 * App\Shared\Modules\Module adds the `/api/v1` prefix and the `api` middleware
 * group automatically — do not repeat either here.
 *
 * Every write carries a NAMED limiter. An inline `throttle:60,1` is banned:
 * ThrottleRequests keys guests on domain|ip with no route in the hash, so every
 * inline limit shares one counter and the strictest wins.
 */

Route::middleware('auth:sanctum')->group(function (): void {
    // The student's own timetable, across every teacher they study with.
    Route::get('/schedule', [ScheduleController::class, 'index']);
    Route::get('/schedule/next', [ScheduleController::class, 'next']);

    Route::get('/class-sessions', [ClassSessionController::class, 'index']);
    Route::get('/class-sessions/{session}', [ClassSessionController::class, 'show']);

    Route::middleware('throttle:sessions')->group(function (): void {
        Route::post('/class-sessions', [ClassSessionController::class, 'store']);
        Route::post('/class-sessions/generate', [ClassSessionController::class, 'generate']);
        Route::put('/class-sessions/{session}', [ClassSessionController::class, 'update']);
        Route::post('/class-sessions/{session}/cancel', [ClassSessionController::class, 'cancel']);

        Route::post('/class-sessions/{session}/book', [BookingController::class, 'store']);
        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy']);
    });
});
