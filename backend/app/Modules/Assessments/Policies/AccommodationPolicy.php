<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Accommodation;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may arrange extra time for a student — and who may know one exists.
 *
 * ⚠️ THERE IS NO STUDENT-FACING READ HERE, AND THAT IS FR-056. A classmate must
 * not learn that somebody has an accommodation, and neither must the holder's own
 * payload announce it in a shape a shared screen would render: what they see is a
 * later deadline and a longer duration, which are facts about their own work.
 */
class AccommodationPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::ACCOMMODATIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function manage(User $user, Accommodation $accommodation): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($accommodation))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::ACCOMMODATIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }
}
