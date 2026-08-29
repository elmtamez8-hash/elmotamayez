<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\FeatureFlag;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may throw a switch (spec 011 · FR-047 · FR-048).
 *
 * ⚠️ `flags.manage` IS A PLATFORM PERMISSION — it is in no tenant role's matrix,
 * so the workspace owner fails every method here. A flag is how the platform
 * releases a feature; a teacher able to turn one on for their own workspace is a
 * teacher deciding what the platform has shipped.
 *
 * ⚠️ AND DELETE IS ALLOWED HERE, UNLIKE THE TAXONOMY'S. Nothing stores a
 * reference to a flag row: `Flags::enabled()` answers «off» for a key with no
 * row, so deleting a workspace override restores the platform default and
 * deleting the default switches the key off everywhere. Both are meaningful
 * operations, and neither leaves anything pointing at a row that is gone.
 */
class FeatureFlagPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->manage($user);
    }

    public function view(User $user, FeatureFlag $flag): Response
    {
        return $this->manage($user);
    }

    public function create(User $user): Response
    {
        return $this->manage($user);
    }

    public function update(User $user, FeatureFlag $flag): Response
    {
        return $this->manage($user);
    }

    public function delete(User $user, FeatureFlag $flag): Response
    {
        return $this->manage($user);
    }

    private function manage(User $user): Response
    {
        return $user->can(Permissions::FLAGS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }
}
