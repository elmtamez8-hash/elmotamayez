<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Policies;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Permissions;

class ReviewPolicy
{
    /**
     * Eligibility to review is "did you finish a session with them", which only
     * SubmitReview can answer — the policy covers the one thing that is a pure
     * authorization question: nobody rates themselves.
     */
    public function create(User $user, TeacherProfile $teacher): bool
    {
        return $user->getKey() !== $teacher->user_id;
    }

    public function moderate(User $user): bool
    {
        return $user->can(Permissions::MARKETPLACE_REVIEWS_MODERATE);
    }
}
