<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Collection;

class StartAttempt extends Action
{
    public function handle(Exam $exam, User $student, ?Enrollment $enrollment = null): Attempt
    {
        $seed = random_int(1, PHP_INT_MAX);

        $attempt = Attempt::create([
            'workspace_id' => app(WorkspaceContext::class)->id(),
            'exam_id' => $exam->getKey(),
            'enrollment_id' => $enrollment?->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'in_progress',
            'random_seed' => $seed,
            'started_at' => now(),
        ]);

        return $attempt;
    }

    /**
     * Returns the questions for this attempt, optionally randomized using the
     * attempt's stored seed (reproducible).
     *
     * @return Collection<int, Question>
     */
    public function questionsForAttempt(Attempt $attempt): Collection
    {
        $questions = $attempt->exam->questions()->with('options')->get();

        if (! $attempt->exam->shuffle_questions) {
            return $questions;
        }

        // Seeded Fisher-Yates shuffle (reproducible via the stored seed).
        $seed = $attempt->random_seed;
        mt_srand($seed);
        $shuffled = $questions->values()->all();
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
        }
        mt_srand();

        return collect($shuffled);
    }

    /**
     * Returns the options for a question, optionally shuffled with the attempt's seed.
     *
     * @param  Collection<int, QuestionOption>  $options
     * @return Collection<int, QuestionOption>
     */
    public function optionsForQuestion(Attempt $attempt, Collection $options): Collection
    {
        if (! $attempt->exam->shuffle_answers) {
            return $options;
        }

        $seed = $attempt->random_seed + $options->first()?->question_id ?? 0;
        mt_srand($seed);
        $shuffled = $options->values()->all();
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
        }
        mt_srand();

        return collect($shuffled);
    }
}
