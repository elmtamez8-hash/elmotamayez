<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Policies;

use App\Models\User;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Tenancy\Support\Permissions;

/**
 * Who may ask to leave, and who may finalise it (spec 013 · US6).
 *
 * ⚠️ THE TWO ANSWERS ARE DIFFERENT PEOPLE, WHICH IS THE POINT OF THE POLICY.
 * Requesting is the teacher's — it is their workspace and their decision.
 * Completing revokes access, ends every membership and fixes the recordings'
 * retention, none of it reversible, and FR-032 makes it conditional on money
 * being settled in both directions. A teacher who could press both would be
 * signing off on their own settlement.
 */
class TeacherOffboardingPolicy
{
    /**
     * ⚠️ THE OWNER, NOT ANY MEMBER. An assistant with a broad role inside a
     * teacher's workspace would otherwise be able to wind the whole thing down —
     * the students notified, the listing pulled, the exit queued — over somebody
     * else's business. `workspaces.owner_user_id` is the one column that says
     * whose workspace it is.
     */
    public function request(User $user, int $ownerUserId): bool
    {
        return (int) $user->getKey() === $ownerUserId;
    }

    public function view(User $user, TeacherOffboarding $offboarding): bool
    {
        return (int) $user->getKey() === (int) $offboarding->teacher_user_id
            || $user->can(Permissions::COMPLIANCE_OFFBOARDING_EXECUTE);
    }

    public function execute(User $user): bool
    {
        return $user->can(Permissions::COMPLIANCE_OFFBOARDING_EXECUTE);
    }
}
