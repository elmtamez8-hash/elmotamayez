<?php

declare(strict_types=1);

use App\Modules\Learning\Http\Controllers\EnrollmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/enrollments', [EnrollmentController::class, 'index']);
    Route::post('/courses/{course}/enroll', [EnrollmentController::class, 'enroll']);
    // The course as a curriculum — the tree with a state and a reason on every
    // row. Addressed by COURSE uuid, because that is what the page reaching it
    // holds; the enrolment is found from the viewer, and finding it is the guard.
    Route::get('/courses/{course}/curriculum', [EnrollmentController::class, 'curriculum']);

    // What was said about this course, for the student it was said to (FR-019).
    // The teacher's `/manage/announcements` is a different list answering a
    // different question — it carries who was reached and who has read, which is
    // the publisher's business and a headcount of the class.
    Route::get('/courses/{course}/announcements', [EnrollmentController::class, 'announcements']);

    Route::get('/enrollments/{enrollment}/lessons/{lesson}', [EnrollmentController::class, 'showLesson']);

    // The same item, resolved from the viewer's own enrolment. `/learn/{lesson}`
    // is reached from a page that knows the course uuid, not the enrolment's.
    Route::get('/learn/lessons/{lesson}', [EnrollmentController::class, 'showLessonForViewer']);
    Route::post('/enrollments/{enrollment}/lessons/{lesson}/complete', [EnrollmentController::class, 'completeLesson']);
});
