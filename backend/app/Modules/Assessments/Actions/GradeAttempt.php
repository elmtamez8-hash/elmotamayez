<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Events\AttemptPendingGrading;
use App\Modules\Assessments\Events\ExamSubmitted;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Marks everything a machine can mark, and stops where a person is needed.
 *
 * ⚠️ IT READS THE SNAPSHOT, NEVER THE LIVE QUESTION. `attempt_items` holds what
 * the student was shown when they started; grading against the current question
 * would re-score a paper the teacher edited afterwards (FR-004).
 *
 * ⚠️ AND IT WRITES A ROW FOR EVERY QUESTION, NOT ONLY FOR THE ANSWERED ONES.
 * The old loop walked the submitted payload, so a question the student left
 * blank produced no row at all — and the mistake notebook, derived from
 * `is_correct = false`, was blind to it. A skipped question is the strongest
 * evidence of a gap on the whole page: whoever left it did not know where to
 * begin, while whoever answered wrongly knew and was wrong.
 */
class GradeAttempt extends Action
{
    use LogsActivity;

    /**
     * @param  array<int, array{question_id: int, selected_option_ids?: array<int>, answer_text?: string|null}>  $answersPayload
     *
     * @throws DomainException when another request already claimed this attempt
     */
    public function handle(Attempt $attempt, array $answersPayload): Attempt
    {
        // ⚠️ CLAIM BEFORE ANY WRITE. The check and the write are one statement;
        // reading a status and then acting on it is the race two taps win
        // together, and a duplicated answer set double-counts every mistake in
        // the notebook and doubles the denominator of every wrong_pct.
        if (! $attempt->claimForGrading()) {
            throw new DomainException('This attempt has already been submitted.');
        }

        return DB::transaction(function () use ($attempt, $answersPayload): Attempt {
            $items = $attempt->items()->get();
            $submitted = collect($answersPayload)->keyBy('question_id');

            // The denominator is what was SHOWN, resolved at start time. It used
            // to be recomputed from the exam's live questions, so deleting a
            // question mid-attempt moved the total under the student.
            $totalPoints = (int) $items->sum('points');
            $earnedPoints = 0;
            $needsGrading = false;

            foreach ($items as $item) {
                $row = $submitted->get($item->question_id);
                $isEssay = $item->requiresGrading();

                if ($isEssay) {
                    $needsGrading = true;
                }

                $selected = array_values(array_map('intval', $row['selected_option_ids'] ?? []));
                $correct = ! $isEssay && $this->matchesSnapshot($item, $selected);
                $points = $correct ? $item->points : 0;
                $earnedPoints += $points;

                Answer::create([
                    'workspace_id' => $attempt->workspace_id,
                    'attempt_id' => $attempt->getKey(),
                    'question_id' => $item->question_id,
                    // Written once, here, and never updated: this is what keeps
                    // the duplicated column from drifting (plan.md §Complexity).
                    'student_user_id' => $attempt->student_user_id,
                    'selected_option_ids' => $selected,
                    'answer_text' => $row['answer_text'] ?? null,
                    'is_correct' => $correct,
                    'points' => $points,
                    'requires_grading' => $isEssay,
                ]);
            }

            $maxScore = max($totalPoints, 1);
            $scorePct = round(($earnedPoints / $maxScore) * 100, 2);

            $attempt->update([
                // ⚠️ AN ESSAY-BEARING ATTEMPT NEITHER PASSES NOR FAILS YET, and
                // no ExamPassed is emitted. That event is the certificate
                // contract: firing it on a partial score issues a certificate for
                // half an exam, and the listener is idempotent so it will not
                // issue twice — but it cannot withdraw one that went out.
                'status' => $needsGrading ? Attempt::STATUS_PENDING_GRADING : Attempt::STATUS_GRADED,
                'score' => $scorePct,
                'max_score' => 100,
                'passed' => false,
                'submitted_at' => now(),
            ]);

            $attempt->refresh();

            /*
             | ⚠️ FIRED IN BOTH BRANCHES, BEFORE THE SPLIT. "Submitted" is true the
             | moment the student hands the paper in, whether or not an essay on it
             | still needs a person — and `CompleteExamLessonOnSubmission` hangs off
             | this event to move course progress. Dropping it while restructuring
             | this action broke exam-lesson progress silently: the whole suite
             | stayed green, because every test that covers that listener raises
             | the event by hand rather than going through here.
             |
             | It is deliberately NOT `ExamPassed`. Which way the exam went does not
             | decide whether the lesson was done; sitting it does.
             */
            event(new ExamSubmitted($attempt));

            if ($needsGrading) {
                event(new AttemptPendingGrading($attempt));

                $this->logActivity('submitted.pending_grading', $attempt, [
                    'auto_score' => $scorePct,
                ]);

                return $attempt;
            }

            return app(FinalizeAttempt::class)->handle($attempt);
        });
    }

    /**
     * Compare against the correct set as it stood when the paper was handed over.
     *
     * @param  list<int>  $selected
     */
    private function matchesSnapshot(AttemptItem $item, array $selected): bool
    {
        $correct = $item->correctOptionIds();
        sort($correct);
        sort($selected);

        return $correct === $selected && $correct !== [];
    }
}
