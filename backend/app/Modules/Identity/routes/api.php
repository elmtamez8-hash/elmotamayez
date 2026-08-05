<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\FamilyController;
use App\Modules\Identity\Http\Controllers\ParentController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/register/student', [AuthController::class, 'registerStudent'])
    ->middleware(['throttle:registration', 'idempotent']);
Route::post('/auth/register/parent', [ParentController::class, 'register'])
    ->middleware(['throttle:registration', 'idempotent']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
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

    // Guardians and the students they follow. Replaces /parent/children, which
    // could only express "linked", not who may see what (spec 003).
    Route::get('/family/relations', [FamilyController::class, 'index']);
    Route::post('/family/relations', [FamilyController::class, 'store']);
    Route::get('/family/relations/{uuid}', [FamilyController::class, 'show']);
    Route::patch('/family/relations/{uuid}', [FamilyController::class, 'update']);
    Route::delete('/family/relations/{uuid}', [FamilyController::class, 'destroy']);
});
