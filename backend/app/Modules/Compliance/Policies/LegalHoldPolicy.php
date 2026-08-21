<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Policies;

use App\Models\User;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Tenancy\Support\Permissions;

/**
 * Who may stop an erasure, and who may let it proceed (FR-021 · SC-009).
 *
 * ⚠️ ONE PERMISSION FOR BOTH DIRECTIONS, AND SPLITTING THEM WOULD BE WORSE. An
 * officer who can place a hold but not release one produces holds nobody can lift;
 * one who can release but not place can only ever weaken a protection. The pair is
 * a single authority over whether the platform is obliged to keep something, and
 * `compliance.holds.manage` is that authority.
 *
 * ⚠️ AND IT IS A PLATFORM PERMISSION HELD BY NO TENANT ROLE. A teacher who could
 * place a hold could freeze a student's erasure inside their own workspace and
 * keep the data indefinitely — a decision about the law, taken by whoever holds
 * the data. `RolePermissionMatrix::platformPermissions()` is `all()` minus
 * everything any tenant role holds, so this stays platform-level until somebody
 * puts it in a tenant role on purpose.
 */
class LegalHoldPolicy
{
    public function manage(User $user): bool
    {
        return $user->can(Permissions::COMPLIANCE_HOLDS_MANAGE);
    }

    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, LegalHold $hold): bool
    {
        /*
        | ⚠️ REFUSED OUTRIGHT, AND THE FILAMENT RESOURCE MUST REPEAT IT. A hold is
        | released, never destroyed: the row is the record that an erasure was
        | suspended, by whom and why, which is precisely what an auditor asks about
        | afterwards. And `AppServiceProvider`'s `Gate::before` waves a super admin
        | past every policy method, so this deny does not stop the person most
        | likely to press the button — the same discovery `CreditPackageResource`
        | and `TaxonomyResource` already wrote down.
        */
        return false;
    }
}
