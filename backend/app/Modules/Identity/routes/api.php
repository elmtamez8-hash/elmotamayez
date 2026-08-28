<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\FamilyController;
use App\Modules\Identity\Http\Controllers\ParentController;
use App\Modules\Identity\Http\Controllers\SessionController;
use App\Modules\Identity\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
| ⚠️ The invitation-only door. It carried NO middleware at all until 2026-08-29 —
| the one unthrottled account-minting endpoint on the platform, and the one that
| skipped `RegisterStudent` and therefore the guardian gate for minors.
*/
Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware(['throttle:registration', 'idempotent']);
Route::post('/auth/register/student', [AuthController::class, 'registerStudent'])
    ->middleware(['throttle:registration', 'idempotent']);
Route::post('/auth/register/parent', [ParentController::class, 'register'])
    ->middleware(['throttle:registration', 'idempotent']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

/*
| Asked only after the token is already gone, which is why it carries no auth:
| requiring one would make the question unanswerable. Two fields, no PII, and
| the uuid is something the client has held since sign-in.
*/
Route::get('/auth/sessions/{uuid}/end-reason', [SessionController::class, 'endReason'])
    ->middleware('throttle:public');

/*
| The second half of a sign-in, so it carries no token — there is none yet. The
| challenge is what stands in for one, and it is keyed by the throttle below as
| well as by IP: six digits are brute-forceable from a botnet otherwise.
*/
Route::post('/auth/2fa/challenge', [TwoFactorController::class, 'challenge'])
    ->middleware('throttle:two-factor');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateProfile']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    Route::get('/auth/2fa', [TwoFactorController::class, 'show']);
    Route::post('/auth/2fa/setup', [TwoFactorController::class, 'setup'])->middleware('throttle:two-factor');
    Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor');
    Route::delete('/auth/2fa', [TwoFactorController::class, 'destroy'])->middleware('throttle:two-factor');
    Route::post('/auth/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
        ->middleware('throttle:two-factor');

    // Own devices only. A teacher never reads these, enrolment or not.
    Route::get('/auth/sessions', [SessionController::class, 'index']);
    Route::delete('/auth/sessions/{uuid}', [SessionController::class, 'destroy']);
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
