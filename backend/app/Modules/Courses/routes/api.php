<?php

declare(strict_types=1);

use App\Modules\Courses\Http\Controllers\ChapterController;
use App\Modules\Courses\Http\Controllers\CourseController;
use App\Modules\Courses\Http\Controllers\LessonController;
use App\Modules\Courses\Http\Controllers\SectionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/courses', [CourseController::class, 'index']);
    Route::post('/courses', [CourseController::class, 'store']);
    Route::get('/courses/{course}', [CourseController::class, 'show']);
    Route::put('/courses/{course}', [CourseController::class, 'update']);
    Route::post('/courses/{course}/publish', [CourseController::class, 'publish']);
    Route::delete('/courses/{course}', [CourseController::class, 'destroy']);

    // Course content structure (sections, chapters, lessons).
    Route::get('/courses/{course}/sections', [SectionController::class, 'index']);
    Route::post('/courses/{course}/sections', [SectionController::class, 'store']);
    Route::put('/courses/{course}/sections/{section}', [SectionController::class, 'update']);
    Route::delete('/courses/{course}/sections/{section}', [SectionController::class, 'destroy']);

    Route::post('/courses/{course}/chapters', [ChapterController::class, 'store']);
    Route::put('/courses/{course}/chapters/{chapter}', [ChapterController::class, 'update']);
    Route::delete('/courses/{course}/chapters/{chapter}', [ChapterController::class, 'destroy']);

    Route::post('/courses/{course}/lessons', [LessonController::class, 'store']);
    Route::put('/courses/{course}/lessons/{lesson}', [LessonController::class, 'update']);
    Route::delete('/courses/{course}/lessons/{lesson}', [LessonController::class, 'destroy']);
});
