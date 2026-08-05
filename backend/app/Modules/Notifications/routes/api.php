<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\ContactVerificationController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

/**
 * No public routes in this module. Every path here is about one signed-in
 * person's own messages, so there is nothing for the IsPubliclyListed guard to
 * do — and nothing a guest could legitimately reach.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{uuid}/read', [NotificationController::class, 'markRead']);

    Route::get('/notifications/types', [NotificationPreferenceController::class, 'types']);
    Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'index']);
    Route::put('/notifications/preferences', [NotificationPreferenceController::class, 'update']);
    Route::put('/notifications/quiet-hours', [NotificationPreferenceController::class, 'updateQuietHours']);

    // Named limiter, never an inline one: ThrottleRequests keys guests on
    // domain|ip with no route in the hash, so every inline limit in the app
    // shares one counter and the strictest wins.
    Route::post('/contact-verifications', [ContactVerificationController::class, 'store'])
        ->middleware('throttle:contact-verification');
    Route::post('/contact-verifications/{uuid}/confirm', [ContactVerificationController::class, 'confirm'])
        ->middleware('throttle:contact-verification');
});
