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

        /*
        | ⚠️ `ORDERS_VIEW_ALL` IS A TEACHER PERMISSION, AND A CREDIT ORDER IS THE
        | PLATFORM'S SALE. Left on the general branch, a teacher reads what each
        | of their students paid the platform — uuid by uuid through `show`, since
        | filtering the index alone leaves that door open — and `OrderResource`
        | hands over the signed link to the payer's bank receipt with it.
        |
        | Q-4 moved the seller role to the platform and `approve()` was written to
        | that decision; view and reject are the same decision, and were the two
        | halves left behind.
        */
        if ($order->requiresPlatformApproval()) {
            return $user->can(Permissions::BILLING_PURCHASE_APPROVE)
                ? Response::allow()
                : Response::deny($order->kind->platformRefusal('view'));
        }

        return $user->can(Permissions::ORDERS_VIEW_ALL)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * ⚠️ THE API NEVER CALLS THIS AND FILAMENT ALWAYS DOES — which is how an
     * unconditional `allow()` sat here reading as harmless.
     *
     * `OrderController::index()` filters by hand (`ORDERS_VIEW_ALL`, then the
     * credit-purchase cut) and asks no policy, so nothing in the HTTP surface ever
     * exercised this method. `OrderResource` declares no `canViewAny()`, so
     * Filament falls back here — and `EnsureFilamentAccess` admits
     * `assistant-teacher` to the panel BY NAME. The result was that an assistant
     * opened `/admin/orders` and read every student's email beside the amount they
     * paid, with zero configuration, while `view()` above guards the same fact uuid
     * by uuid on the API. **A Filament list never calls `view()`** — the row-level
     * ability is not consulted for a table, so the careful cut one method up was
     * bypassed by a screen.
     *
     * Found by the spec 010 review (2026-08-22), which exists to enforce exactly
     * this rule — `FR-003`: an assistant reaches no financial data at all.
     */
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::ORDERS_VIEW_ALL)
            ? Response::allow()
            : Response::deny();
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
        //
        // ⚠️ AND SPEC 011 PUTS TWO MORE KINDS BEHIND THE SAME LINE, for a reason
        // that reads backwards at first: a store sale is the TEACHER's own goods,
        // so surely the teacher approves it? No — that is exactly the objection.
        // The seller does not witness that their own price arrived, and here the
        // seller and the approver would be one person clearing a bar
        // (`PAYMENTS_APPROVE` plus their own workspace) they hold by definition.
        // The condition lives on the enum so the three methods below cannot
        // disagree about which kinds it covers.
        if ($order->requiresPlatformApproval()) {
            return $user->can(Permissions::BILLING_PURCHASE_APPROVE)
                ? Response::allow()
                : Response::deny($order->kind->platformRefusal('approve'));
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

        // Refusing a platform sale is the platform's call, for the reason
        // approving it is: the money is owed to the platform and the teacher is
        // the payee downstream (spec 014). A teacher who may reject it may cancel
        // a payment made to someone else.
        if ($order->requiresPlatformApproval()) {
            return $user->can(Permissions::BILLING_PURCHASE_APPROVE)
                ? Response::allow()
                : Response::deny($order->kind->platformRefusal('reject'));
        }

        return $user->can(Permissions::PAYMENTS_REJECT)
            ? Response::allow()
            : Response::deny('You are not authorized to reject payments.');
    }
}
