<?php

declare(strict_types=1);

use App\Modules\Courses\Http\Controllers\ChapterController;
use App\Modules\Courses\Http\Controllers\CourseController;
use App\Modules\Courses\Http\Controllers\LessonController;
use App\Modules\Courses\Http\Controllers\ReferenceTargetController;
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
    /*
    | The subject picker, before there is a course to file. Above `/courses` so
    | no `{course}` binding can ever swallow the word.
    */
    Route::get('/course-subjects', [CourseController::class, 'subjects']);

    Route::get('/courses', [CourseController::class, 'index']);
    Route::post('/courses', [CourseController::class, 'store']);
    Route::get('/courses/{course}', [CourseController::class, 'show']);
    Route::put('/courses/{course}', [CourseController::class, 'update']);
    Route::post('/courses/{course}/publish', [CourseController::class, 'publish']);
    Route::delete('/courses/{course}', [CourseController::class, 'destroy']);

    // The student-facing tree: published nodes only.
    Route::get('/courses/{course}/sections', [SectionController::class, 'index']);

    // The author's tree: drafts included, with the reason each node is hidden.
    // A separate route rather than a flag on the one above — see the controller.
    Route::get('/courses/{course}/tree', [SectionController::class, 'tree']);

    // One item in full — the tree carries no bodies, only the outline.
    Route::get('/courses/{course}/lessons/{lesson}', [LessonController::class, 'show']);

    // What publishing would do to the students already enrolled (FR-049). A read,
    // so it sits here with the other reads rather than under the authoring
    // limiter — and a separate route from the publish itself, because a "just
    // tell me" flag on a write is one forgotten parameter away from doing it.
    Route::get('/courses/{course}/tree/publish-preview', [SectionController::class, 'publishPreview']);

    // What a reference item may point at: this course's published exams and its
    // sessions. Read by the two pickers in the item editor.
    Route::get('/courses/{course}/reference-targets', [ReferenceTargetController::class, 'index']);

    /*
    | Authoring. `throttle:authoring` is named, like every other limiter in this
    | codebase: ThrottleRequests keys guests on domain|ip with no route in the
    | hash, so every inline throttle:N,M shares one counter and the strictest
    | wins — browsing the marketplace once locked a visitor out of logging in.
    */
    Route::middleware('throttle:authoring')->group(function (): void {
        // Whole-tree state change. One request rather than one per node: a
        // section and its items become visible together, and eleven separate
        // calls give the student eleven different half-built trees on the way.
        Route::post('/courses/{course}/tree/publish', [SectionController::class, 'publishTree']);

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
        // Read what it costs, then do it. Two routes, because a "preview" flag
        // on the write is one forgotten parameter away from doing the thing.
        Route::get('/courses/{course}/lessons/{lesson}/type/{type}', [LessonController::class, 'typeChangePreview']);
        Route::put('/courses/{course}/lessons/{lesson}/type', [LessonController::class, 'changeType']);
        Route::delete('/courses/{course}/lessons/{lesson}', [LessonController::class, 'destroy']);
    });
});
