<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class OrderPolicy extends BasePolicy
{
    public function view(User $user, Order $order): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($order))->denied()) {
            return $workspaceCheck;
        }

        if ($order->user_id === $user->getKey()) {
            return Response::allow();
        }

        return $user->can(Permissions::ORDERS_VIEW_ALL)
            ? Response::allow()
            : Response::deny();
    }

    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::ORDERS_CREATE)
            ? Response::allow()
            : Response::deny();
    }

    public function uploadReceipt(User $user, Order $order): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($order))->denied()) {
            return $workspaceCheck;
        }

        return $order->user_id === $user->getKey()
            ? Response::allow()
            : Response::deny('You can only upload receipts for your own orders.');
    }

    /**
     * Starting a payment is the buyer's act and nobody else's.
     *
     * ⚠️ Written as its own ability rather than reused from `view()`: a teacher
     * holding ORDERS_VIEW_ALL passes that one, and paying is not reading.
     */
    public function pay(User $user, Order $order): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($order))->denied()) {
            return $workspaceCheck;
        }

        return $order->user_id === $user->getKey()
            ? Response::allow()
            : Response::deny('You can only pay for your own orders.');
    }

    public function approve(User $user, Order $order): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($order))->denied()) {
            return $workspaceCheck;
        }

        // Approving a credit purchase is minting money, and it is the teacher who
        // gets paid out of the credits once the sessions are delivered (spec
        // 014). PAYMENTS_APPROVE sits in the teacher array and the workspace
        // check above is one the teacher satisfies by definition — so on its own
        // it would let the payee approve a transfer that never happened. And
        // because the two contexts are deliberately isolated, nothing on the
        // settlement side could ever surface it.
        //
        // Q-4 moved the seller role to the platform; this is that decision
        // finished. PAYMENTS_APPROVE keeps working for course orders.
        if ($order->isCreditPurchase()) {
            return $user->can(Permissions::BILLING_PURCHASE_APPROVE)
                ? Response::allow()
                : Response::deny('اعتماد شراء الأرصدة صلاحية منصّية.');
        }

        return $user->can(Permissions::PAYMENTS_APPROVE)
            ? Response::allow()
            : Response::deny('You are not authorized to approve payments.');
    }

    public function reject(User $user, Order $order): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($order))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::PAYMENTS_REJECT)
            ? Response::allow()
            : Response::deny('You are not authorized to reject payments.');
    }
}
