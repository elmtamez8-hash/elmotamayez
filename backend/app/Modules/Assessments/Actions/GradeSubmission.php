<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Events\SubmissionGraded;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Assessments\Support\LatePenalty;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;

/**
 * The teacher marks a hand-in, and the lateness is charged once (FR-050).
 *
 * ⚠️ `late_penalty_applied_pct` IS FROZEN HERE AND DERIVED NOWHERE. The policy
 * lives in editable columns on the assignment; a teacher who softens it in the
 * last week of term would otherwise re-price every submission already marked —
 * silently, and in both directions, since tightening it works the same way. What
 * is stored is what was charged to this student on this day.
 */
class GradeSubmission extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly LatePenalty $penalties,
    ) {}

    /**
     * @param  float  $rawScore  the mark before lateness — what the work was worth
     *
     * @throws DomainException
     */
    public function handle(Submission $submission, User $grader, float $rawScore, ?string $feedback = null): Submission
    {
        if (! $submission->wasSubmitted()) {
            throw new DomainException('لا شيء سُلّم لتصحيحه.');
        }

        $assignment = $submission->assignment;

        if ($assignment === null) {
            throw new DomainException('التسليم بلا واجب.');
        }

        if ($rawScore < 0) {
            throw new DomainException('الدرجة لا تكون سالبة.');
        }

        if ($rawScore > (float) $assignment->points) {
            throw new DomainException('الدرجة أعلى ممّا يستحقّه الواجب.');
        }

        $penalty = $this->penalties->percentFor($assignment, (int) $submission->late_by_minutes);
        $final = $this->penalties->apply($rawScore, $penalty);

        $submission->forceFill([
            // ⚠️ NEVER BELOW ZERO, whatever the policy says and however late the
            // work (FR-046أ). The floor is applied by LatePenalty and asserted at
            // both boundaries, because the failure is invisible: a negative mark
            // reads as a mark until it is summed with something.
            'score' => $final,
            'late_penalty_applied_pct' => $penalty,
            'feedback' => $feedback,
            'graded_at' => now(),
            'graded_by' => $grader->getKey(),
        ])->save();

        $this->logActivity('assignment.graded', $submission, [
            'raw' => $rawScore,
            'penalty_pct' => $penalty,
            'final' => $final,
        ]);

        event(new SubmissionGraded($submission));

        return $submission->refresh();
    }
}
