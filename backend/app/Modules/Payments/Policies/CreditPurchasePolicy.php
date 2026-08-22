<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * The receipt of a purchase, and the frozen components behind its total.
 *
 * `teacher_rate_minor`, `operating_fee_minor` and `gateway_fee_minor` are stored
 * on the row so the price can be reconstructed years later — and they are the
 * exact three numbers FR-021ب forbids showing a student or a guardian. So the
 * READ here is not the payload: the Resource sends `total` alone, and this
 * policy only decides who may reach the row at all.
 */
class CreditPurchasePolicy extends BasePolicy
{
    public function view(User $user, CreditPurchase $purchase): Response
    {
        $isOwn = CreditBalance::query()
            ->withoutWorkspaceScope()
            ->whereKey($purchase->credit_balance_id)
            ->where('student_user_id', $user->getKey())
            ->exists();

        if ($isOwn) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($purchase))->denied()) {
            return $workspaceCheck;
        }

        /*
        | ⚠️ ORDERS_VIEW_ALL, NOT BILLING_BALANCE_VIEW — moved by spec 010.
        |
        | A purchase IS a payment: FR-003 names "دفعة" and "إيصال" among the
        | surfaces an assistant may never reach, and the row this guards is the
        | receipt for one. `billing.balance.view` says what its name says — a
        | count of credits and a withheld flag — and riding a receipt on it made
        | the two questions one. Reading who paid what belongs with every other
        | order in the product.
        */
        return $user->can(Permissions::ORDERS_VIEW_ALL)
            ? Response::allow()
            : Response::deny();
    }

    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    /**
     * Buying is open; being CREDITED is not.
     *
     * A purchase creates an order of kind `credits` and no credits at all — the
     * credits appear when that order is approved, and approving it needs
     * BILLING_PURCHASE_APPROVE, which no tenant role holds.
     */
    public function create(User $user): Response
    {
        return Response::allow();
    }
}
