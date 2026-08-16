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
 * A grade already given is changed, and the change is kept (FR-032).
 *
 * ⚠️ THE REASON IS MANDATORY AND THE OLD RECORDS SURVIVE. A grade that moves
 * with nothing behind it is the one a student appeals and nobody can answer —
 * not the teacher who changed it, not the assistant who gave it first. The new
 * marks are a NEW generation carrying `revision_of`; nothing is updated, because
 * an UPDATE erases exactly the row being audited.
 *
 * ⚠️ AND THE VERSION READ IS THE VERSION WRITTEN AGAINST, in one statement. Two
 * teachers revising the same answer from the same screen both pass a check made
 * against a loaded model and both write; the loser's marks land on top of the
 * winner's with no trace that two revisions happened. This is the same claim
 * idiom as `StructureVersion::claim()` and seat allocation.
 */
class ReviseGrade extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly EssayMarks $marks,
    ) {}

    /**
     * @param  array<int, array{criterion_id?: int|null, points: float|int|string, comment?: string|null}>  $marks
     *
     * @throws DomainException when the answer was never graded, the reason is missing,
     *                         the marks overflow, or somebody revised it first
     */
    public function handle(Answer $answer, User $grader, array $marks, string $reason): Answer
    {
        if ($answer->graded_at === null) {
            throw new DomainException('This answer has not been graded yet.');
        }

        if (trim($reason) === '') {
            throw new DomainException('A revision needs a reason.');
        }

        $ceiling = $this->marks->ceilingFor($answer);
        $awarded = $this->marks->total($answer, $marks, $ceiling);

        $expected = (int) $answer->grading_version;
        $supersedes = $answer->currentGradingRecords()->orderByDesc('id')->first();

        if (! $answer->claimForRevision($expected, (int) $grader->getKey())) {
            throw new DomainException('This grade has already been revised.');
        }

        return DB::transaction(function () use ($answer, $grader, $marks, $awarded, $ceiling, $expected, $supersedes, $reason): Answer {
            $this->marks->write(
                $answer,
                $grader,
                $marks,
                $expected + 1,
                $supersedes?->getKey() === null ? null : (int) $supersedes->getKey(),
                $reason,
            );

            $answer->forceFill([
                'points' => $awarded,
                'is_correct' => $this->marks->isCorrect($awarded, $ceiling),
                'grading_version' => $expected + 1,
            ])->save();

            $this->logActivity('essay.revised', $answer, [
                'points' => $awarded,
                'of' => $ceiling,
                'reason' => $reason,
            ]);

            /*
            | The total is recomputed and the result re-issued (FR-032). Passing
            | twice is harmless — `IssueCertificateIfEligible` is idempotent, and
            | `ExamFailed` has no listener at all today.
            |
            | ⚠️ BUT NOT WHILE ANOTHER ESSAY ON THE PAPER IS STILL UNREAD, and
            | that condition is the whole of US5 wearing a different door. A
            | grader who marks essay 1, spots a slip and corrects it — one click,
            | since the card then reads «عدّل الدرجة» — would otherwise finalize a
            | paper whose essay 2 nobody has opened: certificate chain and all.
            | Worse afterwards than at the time: `finalizeIfComplete` returns
            | early on an attempt that is no longer pending, so essay 2's marks
            | are then written to its answer row and NEVER enter the total. The
            | student's score silently omits them, permanently.
            |
            | ⚠️ AND WHAT NONE OF THIS CAN DO IS TAKE A CERTIFICATE BACK. A
            | revision turning a pass into a fail leaves an issued certificate
            | standing; there is no withdrawal path in the Certificates module and
            | inventing one here would put it in the wrong context. Named rather
            | than hidden: the grading queue exists so the pass waits for the
            | person in the first place.
            */
            $attempt = $answer->attempt;

            if ($attempt !== null && ! $attempt->isPendingGrading()) {
                app(FinalizeAttempt::class)->handle($attempt);
            }

            return $answer->refresh();
        });
    }
}
