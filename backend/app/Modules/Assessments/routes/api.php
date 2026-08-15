<?php

declare(strict_types=1);

use App\Modules\Assessments\Http\Controllers\AnalyticsController;
use App\Modules\Assessments\Http\Controllers\AttemptController;
use App\Modules\Assessments\Http\Controllers\BankController;
use App\Modules\Assessments\Http\Controllers\ConceptController;
use App\Modules\Assessments\Http\Controllers\ExamController;
use App\Modules\Assessments\Http\Controllers\ExamItemsController;
use App\Modules\Assessments\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Exam management.
    Route::get('/exams', [ExamController::class, 'index']);
    Route::post('/exams', [ExamController::class, 'store']);
    Route::get('/exams/{exam}', [ExamController::class, 'show']);
    Route::put('/exams/{exam}', [ExamController::class, 'update']);
    Route::post('/exams/{exam}/publish', [ExamController::class, 'publish']);
    Route::delete('/exams/{exam}', [ExamController::class, 'destroy']);

    /*
     | ⚠️ THE FOUR NESTED QUESTION ROUTES WERE REMOVED IN SPEC 008, AND REMOVING
     | THEM IS A SECURITY FIX RATHER THAN A CLEANUP.
     |
     | They wrote to the same `questions` table the bank now owns, guarded by
     | `questions.manage` alone — so they were a back door past every rule the
     | bank screen enforces. `DELETE` called `$question->delete()`, a PERMANENT
     | delete of a question with recorded attempts, which FR-005 forbids in favour
     | of disabling. `POST` created a question with no concept, no Bloom level and
     | no content hash, which FR-002 forbids outright.
     |
     | And their only ownership guard was `$question->exam_id !== $exam->id` — a
     | comparison against the column step 6 of the migration chain drops. They
     | would not merely have stayed wrong; they would have stopped working while
     | still accepting requests.
     |
     | Their replacement is `/manage/bank/questions` plus `/manage/exams/{uuid}/items`.
     */

    /*
     | The question bank (spec 008).
     |
     | Reads are `bank.view`, writes are `questions.manage`, and the split is
     | enforced in QuestionPolicy rather than here — a permission named in a route
     | file is a permission the importer and the panel do not consult.
     |
     | ⚠️ `throttle:authoring` IS A NAMED LIMITER, and inline `throttle:60,1` is
     | banned across this codebase: ThrottleRequests keys guests on `domain|ip`
     | with no route in the hash, so every inline limit shares one counter and the
     | strictest one wins.
     */
    Route::prefix('manage/bank')->group(function (): void {
        Route::get('/questions', [BankController::class, 'index']);
        Route::get('/questions/{question}', [BankController::class, 'show']);

        Route::middleware('throttle:authoring')->group(function (): void {
            Route::post('/questions', [BankController::class, 'store']);
            Route::patch('/questions/{question}', [BankController::class, 'update']);
            Route::delete('/questions/{question}', [BankController::class, 'destroy']);

            Route::post('/concepts', [ConceptController::class, 'store']);
            Route::patch('/concepts/{concept}', [ConceptController::class, 'update']);
        });

        Route::get('/concepts', [ConceptController::class, 'index']);

        // The upload answers 202 and hands back an id; the report is polled at
        // the second route, and the notification is what brings the teacher back
        // to it once they have closed the tab.
        Route::get('/imports', [ImportController::class, 'index']);
        Route::get('/imports/{import}', [ImportController::class, 'show']);
        Route::post('/imports', [ImportController::class, 'store'])
            ->middleware('throttle:upload');
    });

    // Which bank questions an exam includes, and in what order. The complete
    // list every time — see SyncExamItems for why a partial edit cannot work.
    Route::get('/manage/exams/{exam}/items', [ExamItemsController::class, 'index']);
    Route::put('/manage/exams/{exam}/items', [ExamItemsController::class, 'sync'])
        ->middleware('throttle:authoring');

    /*
     | Item analysis (spec 008 · US2). Read-only, and read from the rollup.
     |
     | No named limiter: these are GETs behind `auth:sanctum` that touch two
     | small tables and run no aggregate. The cross-teacher view is the same two
     | routes with `?scope=platform`, gated on a permission no tenant role holds
     | — a separate `/admin` path would be a second reader of the same rows, and
     | the one most likely to be left scoped by accident.
     */
    Route::get('/manage/analytics/questions', [AnalyticsController::class, 'questions']);
    Route::get('/manage/analytics/concepts', [AnalyticsController::class, 'concepts']);

    // Attempts (student-facing).
    Route::post('/exams/{exam}/attempts', [AttemptController::class, 'start']);
    Route::post('/attempts/{attempt}/submit', [AttemptController::class, 'submit']);
    Route::get('/attempts/{attempt}', [AttemptController::class, 'show']);
});
