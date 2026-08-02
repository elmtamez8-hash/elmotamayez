<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\ParentController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/register/student', [AuthController::class, 'registerStudent'])
    ->middleware(['throttle:10,1', 'idempotent']);
Route::post('/auth/register/parent', [ParentController::class, 'register'])
    ->middleware(['throttle:10,1', 'idempotent']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateProfile']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/auth/email/verification-notification', [AuthController::class, 'sendVerificationEmail']);
    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');

    Route::get('/parent/children', [ParentController::class, 'children']);
    Route::post('/parent/children', [ParentController::class, 'addChild']);
    Route::get('/parent/children/{uuid}', [ParentController::class, 'showChild']);
    Route::get('/parent/notification-preferences', [ParentController::class, 'notificationPreferences']);
    Route::put('/parent/notification-preferences', [ParentController::class, 'updateNotificationPreferences']);
});
