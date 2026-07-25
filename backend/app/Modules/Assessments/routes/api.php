<?php

declare(strict_types=1);

use App\Modules\Assessments\Http\Controllers\AttemptController;
use App\Modules\Assessments\Http\Controllers\ExamController;
use App\Modules\Assessments\Http\Controllers\QuestionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Exam management.
    Route::get('/exams', [ExamController::class, 'index']);
    Route::post('/exams', [ExamController::class, 'store']);
    Route::get('/exams/{exam}', [ExamController::class, 'show']);
    Route::put('/exams/{exam}', [ExamController::class, 'update']);
    Route::post('/exams/{exam}/publish', [ExamController::class, 'publish']);
    Route::delete('/exams/{exam}', [ExamController::class, 'destroy']);

    // Question management (nested under exam).
    Route::get('/exams/{exam}/questions', [QuestionController::class, 'index']);
    Route::post('/exams/{exam}/questions', [QuestionController::class, 'store']);
    Route::put('/exams/{exam}/questions/{question}', [QuestionController::class, 'update']);
    Route::delete('/exams/{exam}/questions/{question}', [QuestionController::class, 'destroy']);

    // Attempts (student-facing).
    Route::post('/exams/{exam}/attempts', [AttemptController::class, 'start']);
    Route::post('/attempts/{attempt}/submit', [AttemptController::class, 'submit']);
    Route::get('/attempts/{attempt}', [AttemptController::class, 'show']);
});
