<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Platform reference data — constitution v1.2.0 §I, kind (ب).
 *
 * The row has no individual owner and its `workspace_id` is a SCOPE rather than
 * an ownership key, so there is nothing to check ownership against: the
 * permission IS the guard, and `billing.coupons.manage` is held by no tenant
 * role. The workspace owner — the highest tenant role there is — fails every
 * method here, because FR-010 gives authorship to the platform: a discount comes
 * out of the platform's commission, and a teacher who could write one would be
 * spending somebody else's money.
 *
 * ⚠️ READING IS THE SAME PERMISSION AS WRITING, and that is the correction
 * `CreditPackagePolicy` already had to make. The list is the platform's live
 * campaign calendar — every unexpired code, its ceiling and how much of it is
 * gone — which is a far larger disclosure than any single row, and it is exactly
 * the enumeration the buyer-facing refusal is worded to prevent.
 *
 * ⚠️ AND IT IS BOUND EXPLICITLY IN `PaymentsServiceProvider`. Laravel's policy
 * guesser fails OPEN into «no policy applies», so a policy that is written and
 * not registered denies nothing at all — the defect `taxonomy.manage` shipped
 * with in 009, where a permission was declared, seeded, asserted platform-level,
 * and read by no file in the tree.
 */
class CouponPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->create($user);
    }

    public function view(User $user, Coupon $coupon): Response
    {
        return $this->create($user);
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::BILLING_COUPONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Coupon $coupon): Response
    {
        return $this->create($user);
    }

    /**
     * Retire a coupon, never erase it — so this is a flat refusal.
     *
     * `is_active = false` is what the resolver reads, and it takes effect on the
     * next purchase. Deleting the row would orphan every `coupon_redemptions`
     * entry made from it, and those rows are FR-015's record of a discount
     * somebody was actually given — the answer to «why did this order come in
     * fifty riyals light», which is the one question the record exists for.
     *
     * ⚠️ AND THE REFUSAL IS REPEATED ON THE FILAMENT RESOURCE.
     * `BasePolicy::before()` waves a super admin past every policy method, and a
     * super admin is exactly who is standing at that screen — the discovery
     * `CreditPackageResource` already wrote down, reached again from a second
     * module.
     */
    public function delete(User $user, Coupon $coupon): Response
    {
        return Response::deny('يُوقَف الكوبون ولا يُحذَف، لأن كل استعمال تمّ به ما يزال يشير إليه.');
    }
}
