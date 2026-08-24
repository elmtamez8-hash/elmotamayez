<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Events\ReviewSubmitted;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\ReviewEligibility;
use App\Shared\Actions\Action;
use DomainException;

class SubmitReview extends Action
{
    public function __construct(private readonly ReviewEligibility $eligibility) {}

    /**
     * The rule that only a student who actually studied with this teacher may rate
     * them lives here, not in the FormRequest (FR-018, Constitution II): Filament
     * and the seeders reach the same Action without ever building a request.
     *
     * ⚠️ THE GATE CHANGED MEANING IN 010 AND WAS REPLACED IN PLACE. It used to be
     * `hasCompletedSessionWith()` — a COMPLETED ENROLMENT — which refused a student
     * who had sat four live lessons on an active enrolment and admitted one who
     * finished a self-paced course without ever meeting the teacher. FR-030 asks
     * for sessions counted as attended, against a threshold an operator tunes. No
     * second endpoint and no second Action: one question, asked in one place, by
     * {@see ReviewEligibility} — which is also what the eligibility endpoint reads,
     * so the form the student is shown and the answer the server gives cannot drift.
     *
     * ⚠️ AND `rating` IS DERIVED FROM THE AXES, NEVER SUBMITTED BESIDE THEM. It is
     * the average of the axes actually written, so `average_rating` and
     * `TrustScoreCalculator` are untouched by FR-031 — that is `SC-011`. A rating
     * accepted alongside the axes would be a second, disagreeing answer to one
     * question, and the public star would stop matching the bars under it.
     *
     * @param  array{punctuality?: int|null, clarity?: int|null, engagement?: int|null}  $axes
     */
    public function handle(TeacherProfile $teacher, User $student, array $axes, ?string $comment = null): Review
    {
        $status = $this->eligibility->for($teacher, $student);

        if (! $status['eligible']) {
            throw new DomainException((string) $status['reason']);
        }

        $written = array_values(array_filter(
            [$axes['punctuality'] ?? null, $axes['clarity'] ?? null, $axes['engagement'] ?? null],
            static fn (?int $value): bool => $value !== null,
        ));

        if ($written === []) {
            throw new DomainException('لا بدّ من تقييم محور واحد على الأقل.');
        }

        /*
        | ⚠️ KEYED ON THE PERIOD AS WELL AS THE PAIR (FR-032). The shipped unique
        | was the pair alone, which made «one per period» true for free and a second
        | period impossible for ever. A rating inside the live window updates its
        | own row — FR-019's revision — and a rating after it opens the next one.
        */
        $review = Review::query()
            ->withoutWorkspaceScope()
            ->firstOrNew([
                'teacher_profile_id' => $teacher->getKey(),
                'student_id' => $student->getKey(),
                'period_start' => $status['period_start'],
            ]);

        $review->fill([
            // A marketplace student belongs to no workspace, so the auto-fill has
            // nothing to resolve. The review is the teacher's workspace's data.
            'workspace_id' => $teacher->workspace_id,
            'rating' => (int) round(array_sum($written) / count($written)),
            'punctuality' => $axes['punctuality'] ?? null,
            'clarity' => $axes['clarity'] ?? null,
            'engagement' => $axes['engagement'] ?? null,
            'comment' => $comment,
        ])->save();

        event(new ReviewSubmitted($review->fresh() ?? $review));

        return $review;
    }
}
