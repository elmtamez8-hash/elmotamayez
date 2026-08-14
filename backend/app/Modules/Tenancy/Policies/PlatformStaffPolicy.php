<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\PlatformStaff;

/**
 * Who may grant somebody the platform's authority.
 *
 * ⚠️ THE SUPER ADMIN AND NOBODY ELSE, INCLUDING OTHER PLATFORM STAFF. A finance
 * officer who could appoint a finance officer is a finance officer who can grant
 * themselves a colleague, and the delegation stops being traceable to a decision
 * anyone took on purpose. Appointment is the one authority that must not be
 * delegable by the people it appoints.
 *
 * ⚠️ AND THIS TABLE HAS NO GLOBAL SCOPE, because it is platform-owned — one
 * person, one standing, across every workspace. Nothing filters it by tenant, so
 * this class is the entire guard: the same arrangement as `notifications` and
 * `student_credit_accounts`, and the same hazard if a reader assumes otherwise.
 *
 * ⚠️ NO `update`. A standing is granted or revoked; editing one in place would
 * rewrite who was appointed, by whom and why — which is the record itself. The
 * screen offers create and delete, and the row's `reason` is written once.
 */
class PlatformStaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, PlatformStaff $staff): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, PlatformStaff $staff): bool
    {
        return false;
    }

    public function delete(User $user, PlatformStaff $staff): bool
    {
        return $user->isSuperAdmin();
    }
}
