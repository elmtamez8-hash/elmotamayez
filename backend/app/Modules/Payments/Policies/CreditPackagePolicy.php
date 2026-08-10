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
 * ⚠️ READING IS THE SAME PERMISSION AS WRITING, AND THAT IS A CORRECTION.
 * It used to be open to any authenticated user, argued from "a package carries a
 * size and a session type and no price at all" — true of the row, and beside the
 * point of the ENDPOINT. `/admin/billing/packages` lists retired sizes and ones
 * not yet launched, which is the platform's commercial roadmap, and the route's
 * own comment already claimed it was "guarded by PLATFORM permissions that no
 * tenant role holds". One of the two was wrong; this is the one that moved.
 *
 * The STUDENT'S priced list is unaffected — it never comes through here. It goes
 * through ListCreditPackages, guarded by CourseParticipation.
 */
class CreditPackagePolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->create($user);
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
     * Retire a package, never erase it — so this is a flat refusal.
     *
     * `is_active` is what every endpoint reads; the row itself is referenced by
     * every purchase ever made from it, and by credits still being consumed
     * today, so deleting it would leave those receipts pointing at nothing.
     *
     * It used to return {@see self::create()}, which authorised the one operation
     * `routes/api.php` refuses to route — a policy saying yes to an act the
     * router says no to is a door already unlocked, waiting for someone to add
     * the handle. Note that BasePolicy::before() still lets a super-admin past
     * anything, so the refusal that actually holds a BUTTON off the screen is
     * CreditPackageResource::canDelete(); this one stops every holder of
     * BILLING_PACKAGES_MANAGE who is not a super-admin — the delegated finance
     * role among them.
     */
    public function delete(User $user, CreditPackage $package): Response
    {
        return Response::deny('تُتقاعد الحزمة ولا تُحذف، لأن كل عملية شراء تمّت منها ما تزال تشير إليها.');
    }
}
