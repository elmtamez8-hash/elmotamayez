<?php

declare(strict_types=1);

use App\Modules\Courses\Http\Controllers\ChapterController;
use App\Modules\Courses\Http\Controllers\CourseController;
use App\Modules\Courses\Http\Controllers\LessonController;
use App\Modules\Courses\Http\Controllers\SectionController;
use Illuminate\Support\Facades\Route;

/*
| Loaded by App\Shared\Modules\Module, which already applies the /api/v1 prefix
| and the `api` middleware group. Do not repeat either here.
|
| Every node is addressed by uuid. Sections and chapters had no public
| identifier at all until 016 — the endpoints took serial ids, which nothing in
| the product ever called, so nothing ever noticed.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/courses', [CourseController::class, 'index']);
    Route::post('/courses', [CourseController::class, 'store']);
    Route::get('/courses/{course}', [CourseController::class, 'show']);
    Route::put('/courses/{course}', [CourseController::class, 'update']);
    Route::post('/courses/{course}/publish', [CourseController::class, 'publish']);
    Route::delete('/courses/{course}', [CourseController::class, 'destroy']);

    // The student-facing tree: published nodes only.
    Route::get('/courses/{course}/sections', [SectionController::class, 'index']);

    /*
    | Authoring. `throttle:authoring` is named, like every other limiter in this
    | codebase: ThrottleRequests keys guests on domain|ip with no route in the
    | hash, so every inline throttle:N,M shares one counter and the strictest
    | wins — browsing the marketplace once locked a visitor out of logging in.
    */
    Route::middleware('throttle:authoring')->group(function (): void {
        Route::post('/courses/{course}/sections', [SectionController::class, 'store']);
        Route::put('/courses/{course}/sections/order', [SectionController::class, 'reorder']);
        Route::put('/courses/{course}/sections/{section}', [SectionController::class, 'update']);
        Route::delete('/courses/{course}/sections/{section}', [SectionController::class, 'destroy']);

        Route::post('/courses/{course}/chapters', [ChapterController::class, 'store']);
        Route::put('/courses/{course}/sections/{section}/chapters/order', [ChapterController::class, 'reorder']);
        Route::put('/courses/{course}/chapters/{chapter}', [ChapterController::class, 'update']);
        Route::delete('/courses/{course}/chapters/{chapter}', [ChapterController::class, 'destroy']);

        Route::post('/courses/{course}/lessons', [LessonController::class, 'store']);
        Route::put('/courses/{course}/chapters/{chapter}/lessons/order', [LessonController::class, 'reorder']);
        Route::put('/courses/{course}/lessons/{lesson}', [LessonController::class, 'update']);
        Route::delete('/courses/{course}/lessons/{lesson}', [LessonController::class, 'destroy']);
    });
});
