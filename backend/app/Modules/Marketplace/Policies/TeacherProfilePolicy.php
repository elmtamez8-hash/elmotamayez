<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Policies;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Permissions;

/**
 * Authorization for the workspace-facing side of a teacher profile.
 *
 * The public marketplace routes are unauthenticated and therefore never reach this
 * policy — their guard is the publiclyListed() scope, not authorization.
 */
class TeacherProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::MARKETPLACE_TEACHERS_REVIEW);
    }

    public function view(User $user, TeacherProfile $profile): bool
    {
        return $user->getKey() === $profile->user_id
            || $user->can(Permissions::MARKETPLACE_TEACHERS_REVIEW);
    }

    public function update(User $user, TeacherProfile $profile): bool
    {
        return $user->getKey() === $profile->user_id;
    }

    public function approve(User $user): bool
    {
        return $user->can(Permissions::MARKETPLACE_TEACHERS_APPROVE);
    }

    public function suspend(User $user): bool
    {
        return $user->can(Permissions::MARKETPLACE_TEACHERS_SUSPEND);
    }
}
