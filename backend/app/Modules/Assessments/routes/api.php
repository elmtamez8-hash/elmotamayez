<?php

declare(strict_types=1);

use App\Modules\Assessments\Http\Controllers\AttemptController;
use App\Modules\Assessments\Http\Controllers\ExamController;
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

    // Attempts (student-facing).
    Route::post('/exams/{exam}/attempts', [AttemptController::class, 'start']);
    Route::post('/attempts/{attempt}/submit', [AttemptController::class, 'submit']);
    Route::get('/attempts/{attempt}', [AttemptController::class, 'show']);
});
