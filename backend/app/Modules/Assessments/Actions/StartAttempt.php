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
use DomainException;
use Illuminate\Support\Collection;

class StartAttempt extends Action
{
    /**
     * @throws DomainException when the student has used up the exam's attempt allowance
     */
    public function handle(Exam $exam, User $student, ?Enrollment $enrollment = null): Attempt
    {
        // Resolve the enrollment when the caller didn't supply one, otherwise the
        // ExamPassed → certificate chain has nothing to attach the certificate to.
        $enrollment ??= $this->enrollmentFor($exam, $student);

        $this->guardAttemptLimit($exam, $student);

        // exam_attempts.random_seed is an unsignedInteger column — keep the seed
        // inside 32 bits or strict-mode MySQL rejects the insert.
        $seed = random_int(1, 2147483647);

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
     * The student's enrollment in the exam's course, if the exam belongs to one.
     */
    private function enrollmentFor(Exam $exam, User $student): ?Enrollment
    {
        if ($exam->course_id === null) {
            return null;
        }

        return Enrollment::query()
            ->where('course_id', $exam->course_id)
            ->where('student_user_id', $student->getKey())
            ->first();
    }

    /**
     * @throws DomainException
     */
    private function guardAttemptLimit(Exam $exam, User $student): void
    {
        $used = Attempt::query()
            ->where('exam_id', $exam->getKey())
            ->where('student_user_id', $student->getKey())
            ->count();

        if ($used >= $exam->max_attempts) {
            throw new DomainException('You have used all '.$exam->max_attempts.' attempts for this exam.');
        }
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

        // Vary the seed per question so two questions of the same attempt don't
        // shuffle their options identically.
        $seed = $attempt->random_seed + ($options->isEmpty() ? 0 : $options->first()->question_id);
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
