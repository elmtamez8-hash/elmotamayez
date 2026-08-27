<?php

declare(strict_types=1);

use App\Modules\Assessments\Http\Controllers\AccommodationController;
use App\Modules\Assessments\Http\Controllers\AnalyticsController;
use App\Modules\Assessments\Http\Controllers\AssignmentController;
use App\Modules\Assessments\Http\Controllers\AttemptController;
use App\Modules\Assessments\Http\Controllers\BankController;
use App\Modules\Assessments\Http\Controllers\ConceptController;
use App\Modules\Assessments\Http\Controllers\ExamController;
use App\Modules\Assessments\Http\Controllers\ExamItemsController;
use App\Modules\Assessments\Http\Controllers\GradingController;
use App\Modules\Assessments\Http\Controllers\ImportController;
use App\Modules\Assessments\Http\Controllers\MistakeController;
use App\Modules\Assessments\Http\Controllers\PracticeController;
use App\Modules\Assessments\Http\Controllers\SubmissionFileController;
use App\Modules\Assessments\Http\Controllers\UnlockRuleController;
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

    /*
     | The mistake notebook, and the paper built from it (spec 008 · US3).
     |
     | ⚠️ The GET carries no limiter and the POST carries `throttle:practice`,
     | and the asymmetry is the point: reading is two queries over one student's
     | own rows, while building writes an attempt plus a row per question. The
     | limiter is keyed by USER and not by ip — the callers are students, and
     | students sit in classrooms behind one address, where an ip key lets one
     | bored pupil in the back row lock their whole class out of revising.
     */
    Route::get('/mistakes', [MistakeController::class, 'index']);

    /*
     | What the filter bar may offer — derived from the notebook's own query, so
     | it can never offer an option the notebook answers empty. Four grouped
     | reads over the reader's own rows, loaded once per visit; no limiter, for
     | the same reason the notebook itself carries none.
     */
    Route::get('/mistakes/filters', [MistakeController::class, 'options']);

    Route::post('/practice/from-mistakes', [MistakeController::class, 'practice'])
        ->middleware('throttle:practice');

    /*
     | The self-generated paper (spec 008 · US4), and reading it back marked.
     |
     | The result route carries no limiter and needs none — it is a read of two
     | small tables belonging to one attempt. The generator carries the same
     | user-keyed limiter as the mistake paper: each call writes an attempt plus
     | a row per question.
     */
    /*
    | The pickers, before there is a paper to pick for.
    |
    | No limiter: it is a read scoped to the caller's own enrolments, and the
    | screen asks for it once per visit.
    */
    Route::get('/practice/filters', [PracticeController::class, 'options']);

    Route::post('/practice/exams', [PracticeController::class, 'store'])
        ->middleware('throttle:practice');
    Route::get('/practice/attempts/{attempt}/result', [PracticeController::class, 'result']);

    /*
     | The grading board (spec 008 · US5).
     |
     | The two GETs carry no limiter — they are reads behind `grading.perform`
     | with a declared eager-load budget. The writes carry `throttle:authoring`,
     | the same limiter the bank uses: each one appends to an append-only table
     | and may close an attempt out, notify a student and re-issue a result.
     |
     | ⚠️ The rubric hangs off the QUESTION, not off the exam. A mark scheme
     | belongs to the question wherever it is used, which is the whole point of
     | the bank — putting it on the exam would mean writing it again for every
     | paper the question appears in, and the second copy is the one that drifts.
     */
    Route::prefix('manage/grading')->group(function (): void {
        Route::get('/queue', [GradingController::class, 'queue']);
        Route::get('/attempts/{attempt}', [GradingController::class, 'show']);

        Route::middleware('throttle:authoring')->group(function (): void {
            Route::post('/answers/{answer}', [GradingController::class, 'grade']);
            Route::patch('/answers/{answer}', [GradingController::class, 'revise']);
            Route::patch('/settings', [GradingController::class, 'updateSettings']);
        });
    });

    Route::put('/manage/bank/questions/{question}/rubric', [GradingController::class, 'saveRubric'])
        ->middleware('throttle:authoring');

    /*
     | Homework (spec 008 · US6).
     |
     | One list endpoint with two branches rather than two routes: the teacher's
     | view legitimately includes drafts, and a second route over the same table
     | is a second place to forget that filter.
     |
     | The hand-in carries `throttle:upload` because it may carry a file; the
     | writes a teacher makes carry `throttle:authoring`, the same limiter the
     | bank and the grading board use. Reads carry none — they are indexed
     | queries behind `auth:sanctum` with their counts in the query.
     */
    // The pickers for the list above, from the list's own predicate.
    Route::get('/assignments/filters', [AssignmentController::class, 'filters']);

    Route::get('/assignments', [AssignmentController::class, 'index']);
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);
    Route::post('/assignments/{assignment}/submissions', [AssignmentController::class, 'submit'])
        ->middleware('throttle:upload');

    Route::middleware('throttle:authoring')->group(function (): void {
        Route::post('/manage/assignments', [AssignmentController::class, 'store']);
        Route::patch('/manage/assignments/{assignment}', [AssignmentController::class, 'update']);
        Route::post('/manage/assignments/{assignment}/publish', [AssignmentController::class, 'publish']);
        Route::post('/manage/assignments/{assignment}/extensions', [AssignmentController::class, 'extend']);
        Route::post('/manage/submissions/{submission}/grade', [AssignmentController::class, 'grade']);

        Route::post('/manage/accommodations', [AccommodationController::class, 'store']);
        Route::delete('/manage/accommodations/{accommodation}', [AccommodationController::class, 'destroy']);
    });

    Route::get('/manage/assignments/{assignment}/submissions', [AssignmentController::class, 'submissions']);
    Route::get('/manage/accommodations', [AccommodationController::class, 'index']);

    /*
     | ⚠️ `signed` AND `auth:sanctum` TOGETHER, and neither is redundant. The
     | signature alone has no reader to compare its `reader` parameter against;
     | the session alone makes the url guessable from a uuid. The policy runs
     | again inside the controller, which is the half a signature cannot express:
     | it proves who asked for the link, never whether they may still read
     | (FR-048أ). Five minutes, declared in the controller.
     |
     | Inside the auth group, so it inherits `auth:sanctum` from it.
     */
    Route::get('/submissions/{submission}/file', SubmissionFileController::class)
        ->middleware('signed')
        ->name('submissions.file');

    /*
     | The unlock condition (spec 008 · US7).
     |
     | Reads and writes are `unlock.rules.manage`, checked in the controller
     | rather than named here — a permission in a route file is a permission the
     | panel and the seeder do not consult. `throttle:authoring` on the writes,
     | the same limiter the bank and the grading board use.
     |
     | The STUDENT's side of this feature has no route here at all: it is
     | `/class-sessions/{uuid}/eligibility`, owned by LiveSessions because that
     | module binds the session. Both answers come from one resolver.
     */
    Route::get('/manage/unlock-rules', [UnlockRuleController::class, 'index']);

    Route::middleware('throttle:authoring')->group(function (): void {
        Route::post('/manage/unlock-rules', [UnlockRuleController::class, 'store']);
        Route::post('/manage/class-sessions/{session}/unlock-exemptions', [UnlockRuleController::class, 'exempt']);
    });

    // Attempts (student-facing).
    Route::post('/exams/{exam}/attempts', [AttemptController::class, 'start']);
    Route::post('/attempts/{attempt}/submit', [AttemptController::class, 'submit']);
    Route::get('/attempts/{attempt}', [AttemptController::class, 'show']);
});
