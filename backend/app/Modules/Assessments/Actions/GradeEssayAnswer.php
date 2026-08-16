<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Support\EssayMarks;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A person reads one essay and awards it marks (FR-029 · FR-031).
 *
 * ⚠️ THE CLAIM IS ON THE ANSWER AND IT COMES FIRST. Two assistants opening the
 * same queue is the ordinary case, not the exotic one: both read "ungraded",
 * both insert their marks, and the answer ends up carrying the sum of two
 * people's opinions. `WHERE graded_at IS NULL` in the same statement that sets
 * it is the whole guard — `lockForUpdate()` is refused, being a no-op on SQLite,
 * so a test built around it passes here and proves nothing about MySQL.
 */
class GradeEssayAnswer extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly EssayMarks $marks,
    ) {}

    /**
     * @param  array<int, array{criterion_id?: int|null, points: float|int|string, comment?: string|null}>  $marks
     *
     * @throws DomainException when the answer needs no grading, the marks overflow the
     *                         question, or another grader claimed it first
     */
    public function handle(Answer $answer, User $grader, array $marks): Answer
    {
        if (! $answer->requires_grading) {
            throw new DomainException('This answer was marked by machine.');
        }

        $ceiling = $this->marks->ceilingFor($answer);
        $awarded = $this->marks->total($answer, $marks, $ceiling);

        // ⚠️ AFTER THE ARITHMETIC AND BEFORE ANY INSERT. Claiming first would
        // hand a rejected set of marks the claim and leave the answer stamped
        // graded with nothing behind it; claiming later means the conflict is
        // found after the rows are already written, and an append-only table
        // cannot be rolled back by deleting from it.
        if (! $answer->claimForGrading((int) $grader->getKey())) {
            throw new DomainException('Another grader has already marked this answer.');
        }

        return DB::transaction(function () use ($answer, $grader, $marks, $awarded, $ceiling): Answer {
            $this->marks->write($answer, $grader, $marks, (int) $answer->grading_version);

            $answer->forceFill([
                'points' => $awarded,
                'is_correct' => $this->marks->isCorrect($awarded, $ceiling),
            ])->save();

            $this->logActivity('essay.graded', $answer, [
                'points' => $awarded,
                'of' => $ceiling,
            ]);

            $this->finalizeIfComplete($answer);

            return $answer->refresh();
        });
    }

    /**
     * Close the attempt out once nothing on it is still waiting on a person.
     *
     * ⚠️ THE CLAIM IS WHAT MAKES THIS SAFE. Two graders finishing the last two
     * essays both count zero remaining; without it both finalize, and the
     * student is told their result twice by two different people. `ExamPassed`
     * survives that — its certificate listener is idempotent — but a
     * notification has no such defence.
     */
    private function finalizeIfComplete(Answer $answer): void
    {
        $attempt = $answer->attempt;

        if ($attempt === null || ! $attempt->isPendingGrading()) {
            return;
        }

        $stillWaiting = Answer::query()
            ->where('attempt_id', $attempt->getKey())
            ->where('requires_grading', true)
            ->whereNull('graded_at')
            ->exists();

        if ($stillWaiting || ! $attempt->claimForFinalize()) {
            return;
        }

        app(FinalizeAttempt::class)->handle($attempt->refresh());
    }
}
