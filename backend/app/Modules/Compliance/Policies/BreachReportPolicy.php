<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Policies;

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;

/**
 * Who may read and triage a reported breach (spec 013 · FR-040).
 *
 * ⚠️ THERE IS NO `create()` HERE, AND THE ABSENCE IS THE REQUIREMENT. Reporting is
 * unauthenticated by design — a policy method for it would be a hook somebody
 * later "tightens" into the account check that closes the route to the people it
 * exists for.
 */
class BreachReportPolicy
{
    public function manage(User $user): bool
    {
        return $user->can(Permissions::COMPLIANCE_BREACHES_MANAGE);
    }
}
