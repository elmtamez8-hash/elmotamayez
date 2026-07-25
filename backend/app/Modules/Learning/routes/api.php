<?php

declare(strict_types=1);

use App\Modules\Learning\Http\Controllers\EnrollmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/enrollments', [EnrollmentController::class, 'index']);
    Route::post('/courses/{course}/enroll', [EnrollmentController::class, 'enroll']);
    Route::get('/enrollments/{enrollment}/lessons/{lesson}', [EnrollmentController::class, 'showLesson']);
    Route::post('/enrollments/{enrollment}/lessons/{lesson}/complete', [EnrollmentController::class, 'completeLesson']);
});
