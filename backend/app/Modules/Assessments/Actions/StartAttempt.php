<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StartAttempt extends Action
{
    /**
     * @throws DomainException when the student has used up the exam's attempt allowance
     */
    public function handle(Exam $exam, User $student, ?Enrollment $enrollment = null, bool $isPractice = false): Attempt
    {
        // Resolve the enrollment when the caller didn't supply one, otherwise the
        // ExamPassed → certificate chain has nothing to attach the certificate to.
        $enrollment ??= $this->enrollmentFor($exam, $student);

        if (! $isPractice) {
            $this->guardAttemptLimit($exam, $student);
        }

        // exam_attempts.random_seed is an unsignedInteger column — keep the seed
        // inside 32 bits or strict-mode MySQL rejects the insert.
        $seed = random_int(1, 2147483647);

        return DB::transaction(function () use ($exam, $student, $enrollment, $seed, $isPractice): Attempt {
            $attempt = Attempt::create([
                // Take the workspace from the exam, not the ambient context: an actor
                // operating globally (Super Admin with no current workspace) would
                // otherwise write a NULL workspace_id.
                'workspace_id' => $exam->workspace_id,
                'exam_id' => $exam->getKey(),
                'enrollment_id' => $enrollment?->getKey(),
                'student_user_id' => $student->getKey(),
                'status' => 'in_progress',
                'is_practice' => $isPractice,
                'random_seed' => $seed,
                'started_at' => now(),
            ]);

            $this->freezeItems($attempt, $exam);

            return $attempt;
        });
    }

    /**
     * Write what the student is being shown, now, before they can answer.
     *
     * ⚠️ AT START AND NOT AT SUBMIT. "What they saw" is defined at the moment the
     * paper is handed over — an edit landing mid-attempt does not change the paper
     * somebody is already holding (FR-004).
     *
     * And it fixes the denominator at the same instant: it used to be recomputed
     * from the exam's live questions at grading time, so a question deleted while
     * a student was working moved their total underneath them.
     */
    private function freezeItems(Attempt $attempt, Exam $exam): void
    {
        $items = $exam->items()->with('question.options')->get();

        foreach ($items as $index => $item) {
            $question = $item->question;

            AttemptItem::create([
                'workspace_id' => $attempt->workspace_id,
                'attempt_id' => $attempt->getKey(),
                'question_id' => $question->getKey(),
                'order' => $item->order !== 0 ? $item->order : $index + 1,
                'points' => $item->effectivePoints(),
                'snapshot' => [
                    'type' => $question->type,
                    'content' => $question->content,
                    'explanation' => $question->explanation,
                    'options' => $question->options
                        ->map(fn (QuestionOption $option) => [
                            'id' => $option->getKey(),
                            'content' => $option->content,
                            'order' => $option->order,
                        ])->values()->all(),
                    'correct_option_ids' => $question->options
                        ->filter(fn (QuestionOption $option) => $option->is_correct)
                        ->map(fn (QuestionOption $option) => $option->getKey())
                        ->values()->all(),
                ],
            ]);
        }
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
            // ⚠️ PRACTICE RUNS DO NOT SPEND OFFICIAL ATTEMPTS (FR-026أ). Without
            // this line the first revision session eats a graded chance, so the
            // feature that exists to help a student prepare is the feature that
            // stops them sitting the exam.
            ->where('is_practice', false)
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
        // ⚠️ THROUGH THE FROZEN ITEMS, NOT THROUGH THE LIVE EXAM. Reading the exam
        // means a question added or removed mid-attempt changes the paper in the
        // student's hands, and a question deleted after the fact makes the review
        // of a graded attempt impossible to render.
        $questions = $attempt->items()
            ->with('question.options')
            ->get()
            ->map(fn (AttemptItem $item) => $item->question)
            ->filter()
            ->values();

        if ($attempt->exam === null || ! $attempt->exam->shuffle_questions) {
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
        if ($attempt->exam === null || ! $attempt->exam->shuffle_answers) {
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
