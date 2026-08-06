<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Http\Controllers\AttendanceController;
use App\Modules\LiveSessions\Http\Controllers\BookingController;
use App\Modules\LiveSessions\Http\Controllers\BroadcastController;
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
    Route::get('/class-sessions/{session}/attendance', [AttendanceController::class, 'index']);

    Route::middleware('throttle:sessions')->group(function (): void {
        Route::post('/class-sessions', [ClassSessionController::class, 'store']);
        Route::post('/class-sessions/generate', [ClassSessionController::class, 'generate']);
        Route::put('/class-sessions/{session}', [ClassSessionController::class, 'update']);
        Route::post('/class-sessions/{session}/cancel', [ClassSessionController::class, 'cancel']);

        Route::post('/class-sessions/{session}/book', [BookingController::class, 'store']);
        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy']);

        Route::post('/class-sessions/{session}/join', [BroadcastController::class, 'join']);
        Route::post('/class-sessions/{session}/leave', [BroadcastController::class, 'leave']);
        Route::post('/class-sessions/{session}/host/{action}', [BroadcastController::class, 'host']);

        Route::post('/attendances/{attendance}/override', [AttendanceController::class, 'override']);
        Route::post('/class-sessions/{session}/feedback', [AttendanceController::class, 'feedback']);
    });

    // Its own limiter: one participant sends two a minute, and the ceiling has
    // to leave room for several rooms and reconnection storms without sharing a
    // bucket with the booking endpoints.
    Route::post('/class-sessions/{session}/presence', [BroadcastController::class, 'presence'])
        ->middleware('throttle:presence');
});
