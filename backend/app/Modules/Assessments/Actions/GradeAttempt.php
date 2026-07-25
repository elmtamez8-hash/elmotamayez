<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Events\ExamFailed;
use App\Modules\Assessments\Events\ExamPassed;
use App\Modules\Assessments\Events\ExamSubmitted;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Question;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

/**
 * Grades a submitted exam attempt:
 * - Records each answer with its correctness.
 * - Computes the score as a percentage.
 * - Marks the attempt as passed/failed against the exam's passing score.
 * - Fires ExamSubmitted → ExamPassed/ExamFailed.
 */
class GradeAttempt extends Action
{
    use LogsActivity;

    /**
     * @param  array<int, array{question_id: int, selected_option_ids: array<int>}>  $answersPayload
     */
    public function handle(Attempt $attempt, array $answersPayload): Attempt
    {
        return DB::transaction(function () use ($attempt, $answersPayload): Attempt {
            $attempt->load('exam.questions.options');

            $totalPoints = 0;
            $earnedPoints = 0;

            foreach ($answersPayload as $row) {
                $question = $attempt->exam->questions->firstWhere('id', $row['question_id']);
                if ($question === null) {
                    continue;
                }

                $totalPoints += $question->points;

                $correct = $this->isAnswerCorrect($question, $row['selected_option_ids'] ?? []);
                $points = $correct ? $question->points : 0;
                $earnedPoints += $points;

                Answer::create([
                    'workspace_id' => $attempt->workspace_id,
                    'attempt_id' => $attempt->getKey(),
                    'question_id' => $question->getKey(),
                    'selected_option_ids' => $row['selected_option_ids'] ?? [],
                    'is_correct' => $correct,
                    'points' => $points,
                ]);
            }

            $maxScore = max($totalPoints, 1);
            $scorePct = round(($earnedPoints / $maxScore) * 100, 2);
            $passed = $scorePct >= $attempt->exam->passing_score;

            $attempt->update([
                'status' => 'graded',
                'score' => $scorePct,
                'max_score' => 100,
                'passed' => $passed,
                'submitted_at' => now(),
            ]);

            event(new ExamSubmitted($attempt->fresh()));

            $this->logActivity('submitted', $attempt, [
                'score' => $scorePct,
                'passed' => $passed,
            ]);

            if ($passed) {
                event(new ExamPassed($attempt->fresh()));
            } else {
                event(new ExamFailed($attempt->fresh()));
            }

            return $attempt->fresh();
        });
    }

    /**
     * @param  array<int>  $selectedOptionIds
     */
    private function isAnswerCorrect(Question $question, array $selectedOptionIds): bool
    {
        $correctOptionIds = $question->options->where('is_correct', true)->pluck('id')->sort()->values()->all();
        $selected = collect($selectedOptionIds)->sort()->values()->all();

        return $correctOptionIds === $selected && count($correctOptionIds) > 0;
    }
}
