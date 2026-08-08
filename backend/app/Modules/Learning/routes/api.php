<?php

declare(strict_types=1);

use App\Modules\Learning\Http\Controllers\EnrollmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/enrollments', [EnrollmentController::class, 'index']);
    Route::post('/courses/{course}/enroll', [EnrollmentController::class, 'enroll']);
    Route::get('/enrollments/{enrollment}/lessons/{lesson}', [EnrollmentController::class, 'showLesson']);

    // The same item, resolved from the viewer's own enrolment. `/learn/{lesson}`
    // is reached from a page that knows the course uuid, not the enrolment's.
    Route::get('/learn/lessons/{lesson}', [EnrollmentController::class, 'showLessonForViewer']);
    Route::post('/enrollments/{enrollment}/lessons/{lesson}/complete', [EnrollmentController::class, 'completeLesson']);
});
