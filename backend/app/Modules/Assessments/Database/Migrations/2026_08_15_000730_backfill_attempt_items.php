<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Step 7d. Rebuild `attempt_items` for attempts that predate this spec.
|
| ⚠️ WITHOUT THIS THE SUITE STILL PASSES AND THE PRODUCT IS BROKEN. From step 7a
| onwards the denominator of an attempt is "what was shown", read from
| `attempt_items`. An attempt graded before 008 has no rows there, so its
| denominator becomes ZERO — a review screen with no questions on it, or a
| division by zero, depending on which branch runs first.
|
| And the score itself is untouched, sitting on `exam_attempts.score`. So a test
| written against SC-015 — "100% of past attempts survive with a difference of
| zero" — compares that column, finds it identical, and reports success about a
| page nobody can open. That is the exact family of defect that cost spec 016:
| an item that enters the denominator and can never be satisfied.
|
| ⚠️ THE SNAPSHOT IS MARKED `backfilled`. It is reconstructed from the question
| as it stands TODAY, which is not what the student saw — if the teacher has
| edited it since, this is the edited version. Presenting a reconstruction as
| testimony would be worse than admitting it is one, so the flag travels with the
| row and the review screen says so.
*/
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('exam_answers')
            ->select(
                'exam_answers.id',
                'exam_answers.attempt_id',
                'exam_answers.question_id',
                'exam_answers.workspace_id',
                'exam_answers.points',
                'questions.content',
                'questions.type',
            )
            ->join('questions', 'questions.id', '=', 'exam_answers.question_id')
            ->orderBy('exam_answers.id')
            ->chunkById(500, function ($answers) use ($now): void {
                $rows = [];

                // One options query for the whole chunk, not one per answer: this
                // walks every answer row on the platform, and a query per row is
                // the difference between a migration and an outage.
                $optionsByQuestion = DB::table('question_options')
                    ->whereIn('question_id', $answers->pluck('question_id')->unique()->all())
                    ->orderBy('order')
                    ->get(['id', 'question_id', 'content', 'is_correct'])
                    ->groupBy('question_id');

                foreach ($answers as $answer) {
                    $options = $optionsByQuestion->get($answer->question_id) ?? collect();

                    $rows[] = [
                        'workspace_id' => $answer->workspace_id,
                        'attempt_id' => $answer->attempt_id,
                        'question_id' => $answer->question_id,
                        'order' => 0,
                        'points' => $answer->points > 0 ? $answer->points : 1,
                        'snapshot' => json_encode([
                            'backfilled' => true,
                            'type' => $answer->type,
                            'content' => $answer->content,
                            'options' => $options->map(fn ($option) => [
                                'id' => $option->id,
                                'content' => $option->content,
                            ])->all(),
                            'correct_option_ids' => $options
                                ->filter(fn ($option) => (bool) $option->is_correct)
                                ->pluck('id')->values()->all(),
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('attempt_items')->insertOrIgnore($rows);
                }
            }, 'exam_answers.id', 'id');
    }

    public function down(): void
    {
        DB::table('attempt_items')->delete();
    }
};
