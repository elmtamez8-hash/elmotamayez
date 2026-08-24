<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\ReviewEligibility;
use App\Shared\Actions\Action;

/**
 * What the review form should show before the student fills it in (FR-030).
 *
 * ⚠️ IT ASKS THE AUTHORISER'S OWN PREDICATE AND ADDS NOTHING. `ReviewEligibility`
 * is the same object `SubmitReview` refuses with, so an offer this endpoint makes
 * is an offer the server accepts, and a refusal it reports is the refusal the
 * server would give — with the same sentence. Assembled beside the gate instead,
 * it shows a form that 422s and hides one that would have worked; that is the
 * `ListLeaderboardScopes` defect, and `SC-010`'s test walks this answer through the
 * real endpoint for exactly that reason.
 *
 * ⚠️ AND IT REFUSES THE TEACHER THEMSELVES. `ReviewPolicy::create()` is the one
 * pure authorisation question here — nobody rates themselves — and leaving it out
 * would tell a teacher opening their own public profile how many of their own
 * sessions they have «attended».
 */
class ReadReviewEligibility extends Action
{
    public function __construct(private readonly ReviewEligibility $eligibility) {}

    /** @return array<string, mixed> */
    public function handle(TeacherProfile $teacher, User $student): array
    {
        if ($student->getKey() === $teacher->user_id) {
            return [
                'eligible' => false,
                'attended_sessions' => 0,
                'required_sessions' => 0,
                'period_start' => null,
                'period_end' => null,
                'is_revision' => false,
                'reason' => 'لا يمكنك تقييم نفسك.',
            ];
        }

        return $this->eligibility->for($teacher, $student);
    }
}
