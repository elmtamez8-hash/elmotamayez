<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Events\AttemptPendingGrading;
use App\Modules\Assessments\Events\ExamSubmitted;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Support\AnswerMarker;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Marks everything a machine can mark, and stops where a person is needed.
 *
 * ⚠️ IT READS THE SNAPSHOT, NEVER THE LIVE QUESTION, and it writes a row for
 * EVERY question rather than only the answered ones — a question left blank is
 * the strongest evidence of a gap on the whole page, and the mistake notebook is
 * derived from `is_correct = false`, so a missing row is a gap the notebook
 * cannot see. Both rules now live in {@see AnswerMarker}, which the adaptive
 * path (spec 012) calls one question at a time.
 */
class GradeAttempt extends Action
{
    use LogsActivity;

    public function __construct(private readonly AnswerMarker $marker) {}

    /**
     * @param  array<int, array{question_id: int, selected_option_ids?: array<int>, answer_text?: string|null}>  $answersPayload
     *
     * @throws DomainException when this attempt already carries answers, or when another request already claimed it
     */
    public function handle(Attempt $attempt, array $answersPayload): Attempt
    {
        /*
         | ⚠️ THIS GUARD IS WHAT KEEPS AN ADAPTIVE SESSION FROM BEING BRICKED
         | PERMANENTLY (spec 012 · T013). `POST /attempts/{attempt}/submit` binds
         | implicitly and authorises on ownership alone, and an adaptive session's
         | attempt belongs to the student — so they can reach this action for an
         | attempt that already carries one answer row per question they have sat.
         | The loop below then writes a row for EVERY item, collides on
         | `unique(attempt_id, question_id)`, and the 500 leaves the attempt at
         | `grading` with no writer in the tree able to put it back.
         |
         | ⚠️ AND IT STANDS BEFORE THE CLAIM, NOT AFTER IT. Refusing after the
         | claim strands the attempt in exactly the state the refusal exists to
         | prevent.
         */
        if (Answer::query()->withoutWorkspaceScope()->where('attempt_id', $attempt->getKey())->exists()) {
            throw new DomainException('هذه المحاولة مصحَّحة بالفعل ولا تُسلَّم مرّةً أخرى.');
        }

        // ⚠️ CLAIM BEFORE ANY WRITE. The check and the write are one statement;
        // reading a status and then acting on it is the race two taps win
        // together, and a duplicated answer set double-counts every mistake in
        // the notebook and doubles the denominator of every wrong_pct.
        if (! $attempt->claimForGrading()) {
            throw new DomainException('This attempt has already been submitted.');
        }

        /*
         | ⚠️ THE CLAIM IS OUTSIDE THE TRANSACTION, SO A ROLLBACK DOES NOT UNDO IT
         | (spec 012 · T014). Without this release a failure anywhere below leaves
         | the attempt at `grading` for ever: nothing in the tree moves a row out
         | of that status except a successful grading, so the student's paper is
         | unsubmittable and unreadable with no way back short of SQL.
         |
         | `Throwable`, not `Exception` — a TypeError in a listener is exactly the
         | kind of failure this exists for. The release is conditional on the
         | status this call itself set, so it can only ever release its own claim.
         */
        try {
            return DB::transaction(fn (): Attempt => $this->grade($attempt, $answersPayload));
        } catch (Throwable $exception) {
            Attempt::query()
                ->whereKey($attempt->getKey())
                ->where('status', Attempt::STATUS_GRADING)
                ->update(['status' => Attempt::STATUS_IN_PROGRESS]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{question_id: int, selected_option_ids?: array<int>, answer_text?: string|null}>  $answersPayload
     */
    private function grade(Attempt $attempt, array $answersPayload): Attempt
    {
        $items = $attempt->items()->get();
        $submitted = collect($answersPayload)->keyBy('question_id');

        /*
         | ⚠️ ONE QUERY BEFORE THE LOOP, NEVER ONE INSIDE IT. Whether a correct
         | answer FIXES something is a question about the student's history, and
         | asking it per question turns the hottest write path in this module into
         | an N+1 that grows with the paper.
         |
         | Read before any answer row is written, deliberately: after the insert
         | below, every question on this paper has a wrong answer of its own to
         | find. That is why `AnswerMarker` takes the set and never derives it.
         */
        $previouslyWrong = $this->marker->previouslyWrongQuestionIds(
            $attempt,
            $items->map(fn (AttemptItem $item): int => (int) $item->question_id)->values()->all(),
        );

        // The denominator is what was SHOWN, resolved at start time. It used to be
        // recomputed from the exam's live questions, so deleting a question
        // mid-attempt moved the total under the student.
        $totalPoints = (int) $items->sum('points');
        $earnedPoints = 0;
        $needsGrading = false;

        foreach ($items as $item) {
            $row = $submitted->get($item->question_id);

            if ($item->requiresGrading()) {
                $needsGrading = true;
            }

            $correct = $this->marker->mark(
                $attempt,
                $item,
                array_values(array_map('intval', $row['selected_option_ids'] ?? [])),
                $row['answer_text'] ?? null,
                $previouslyWrong,
            );

            if ($correct) {
                $earnedPoints += (int) $item->points;
            }
        }

        $maxScore = max($totalPoints, 1);
        $scorePct = round(($earnedPoints / $maxScore) * 100, 2);

        $attempt->update([
            // ⚠️ AN ESSAY-BEARING ATTEMPT NEITHER PASSES NOR FAILS YET, and no
            // ExamPassed is emitted. That event is the certificate contract:
            // firing it on a partial score issues a certificate for half an exam,
            // and the listener is idempotent so it will not issue twice — but it
            // cannot withdraw one that went out.
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
         | this event to move course progress. Dropping it while restructuring this
         | action broke exam-lesson progress silently: the whole suite stayed
         | green, because every test that covers that listener raises the event by
         | hand rather than going through here.
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
    }
}
