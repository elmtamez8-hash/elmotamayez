<?php

declare(strict_types=1);

use App\Modules\Learning\Http\Controllers\CohortController;
use App\Modules\Learning\Http\Controllers\EnrollmentController;
use App\Modules\Learning\Http\Controllers\ManageCohortController;
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

    /*
    | ── المجموعات · الطالب ─────────────────────────────────────────────────
    |
    | ⚠️ EVERY WRITE IS BEHIND THE NAMED LIMITER `cohort-write`. An inline
    | `throttle:N,M` is banned in this tree: `ThrottleRequests` keys guests on
    | `domain|ip` with NO route in the hash, so every inline limit shares one
    | counter and the strictest one in the application wins — browsing the
    | marketplace used to lock a visitor out of logging in.
    */
    Route::get('/courses/{course}/cohorts', [CohortController::class, 'index']);

    Route::middleware('throttle:cohort-write')->group(function (): void {
        Route::post('/cohorts/{cohort}/join', [CohortController::class, 'join']);
        Route::post('/cohorts/{cohort}/transfer-requests', [CohortController::class, 'requestTransfer']);
        Route::delete('/transfer-requests/{transferRequest}', [CohortController::class, 'withdraw']);
    });

    /*
    | ── المجموعات · المدرّس ────────────────────────────────────────────────
    |
    | ⚠️ THERE IS NO `DELETE /manage/cohorts/{cohort}`, and its absence is the
    | requirement (FR-035). Archiving keeps the closed memberships pointing at
    | something that resolves and keeps the history readable; a delete would turn
    | every one of them into a row naming a group that no longer exists.
    */
    Route::get('/manage/courses/{course}/cohorts', [ManageCohortController::class, 'index']);
    Route::get('/manage/cohorts/{cohort}/members', [ManageCohortController::class, 'members']);
    Route::get('/manage/cohorts/{cohort}/history', [ManageCohortController::class, 'history']);
    Route::get('/manage/courses/{course}/students/{student}/cohort-history', [ManageCohortController::class, 'studentHistory']);
    Route::get('/manage/courses/{course}/transfer-requests', [ManageCohortController::class, 'transferRequests']);

    Route::middleware('throttle:cohort-write')->group(function (): void {
        Route::post('/manage/courses/{course}/cohorts', [ManageCohortController::class, 'store']);
        Route::patch('/manage/cohorts/{cohort}', [ManageCohortController::class, 'update']);
        Route::post('/manage/cohorts/{cohort}/archive', [ManageCohortController::class, 'archive']);
        Route::post('/manage/cohorts/{cohort}/members', [ManageCohortController::class, 'addMember']);
        Route::delete('/manage/cohorts/{cohort}/members/{user}', [ManageCohortController::class, 'removeMember']);
        Route::post('/manage/transfer-requests/{transferRequest}/approve', [ManageCohortController::class, 'approve']);
        Route::post('/manage/transfer-requests/{transferRequest}/reject', [ManageCohortController::class, 'reject']);
    });
});
