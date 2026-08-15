<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Events\AttemptPendingGrading;
use App\Modules\Assessments\Events\ExamSubmitted;
use App\Modules\Assessments\Events\MistakeResolved;
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

            /*
             | ⚠️ ONE QUERY BEFORE THE LOOP, NEVER ONE INSIDE IT. Whether a
             | correct answer FIXES something is a question about the student's
             | history, and asking it per question turns the hottest write path
             | in this module into an N+1 that grows with the paper.
             |
             | Read before any answer row is written, deliberately: after the
             | insert below, every question on this paper has a wrong answer of
             | its own to find.
             */
            $previouslyWrong = $this->previouslyWrongQuestionIds($attempt, $items->map(fn (AttemptItem $item): int => (int) $item->question_id)->values()->all());

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

                if ($correct && in_array((int) $item->question_id, $previouslyWrong, true)) {
                    event(new MistakeResolved(
                        (int) $attempt->student_user_id,
                        (int) $item->question_id,
                        (int) $attempt->workspace_id,
                    ));
                }
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
     * Which of these questions this student has already got wrong before.
     *
     * @param  array<int, int>  $questionIds
     * @return list<int>
     */
    private function previouslyWrongQuestionIds(Attempt $attempt, array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }

        $rows = Answer::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $attempt->workspace_id)
            ->where('student_user_id', $attempt->student_user_id)
            ->whereIn('question_id', $questionIds)
            ->where('is_correct', false)
            // The same predicate the notebook uses: an essay nobody has read is
            // not a mistake, so answering it correctly later fixes nothing.
            ->where(fn ($query) => $query->where('requires_grading', false)->orWhereNotNull('graded_at'))
            ->distinct()
            ->pluck('question_id');

        $ids = [];

        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
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
