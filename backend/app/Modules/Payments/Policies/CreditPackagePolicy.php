<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Platform reference data — constitution v1.2.0 §I, kind (ب).
 *
 * The row has no individual owner and no `workspace_id`, so there is nothing to
 * check ownership against: the write permission IS the guard, and it is a
 * PLATFORM permission. The workspace owner — the highest tenant role there is —
 * must fail every write here, because a package the teacher can define is a sale
 * price the teacher sets, which FR-016 and FR-021ب both forbid.
 *
 * Reading is open to any authenticated user: a package carries a size and a
 * session type and no price at all. The price is computed per course, by the
 * pricing endpoint, which has its own party-to-the-course guard.
 */
class CreditPackagePolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::BILLING_PACKAGES_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, CreditPackage $package): Response
    {
        return $this->create($user);
    }

    /**
     * Retire a package, never erase it.
     *
     * `is_active` is what the endpoints read; the row itself is referenced by
     * every purchase ever made from it, and deleting it would leave those
     * receipts pointing at nothing.
     */
    public function delete(User $user, CreditPackage $package): Response
    {
        return $this->create($user);
    }
}
