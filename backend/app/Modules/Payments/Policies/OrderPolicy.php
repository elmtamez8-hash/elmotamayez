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
        // Your own row is yours to read wherever you are standing, so ownership
        // is asked before any tenant check — see `platformReads()` below for why
        // the order of these three questions is load-bearing now.
        if ($order->user_id === $user->getKey()) {
            return Response::allow();
        }

        if (($platform = $this->platformReads($user, $order, 'view')) !== null) {
            return $platform;
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($order))->denied()) {
            return $workspaceCheck;
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

    /**
     * The buyer's own receipt — and, since 024, the officer's on their behalf.
     *
     * ⚠️ NO WORKSPACE CHECK, AND ITS ABSENCE IS SAFE BY CONSTRUCTION: the only
     * two ways out of this method are "you own the row" and "you are the platform
     * approver of a platform sale". Neither is a tenant question, and the one it
     * used to guard against — a teacher reaching another workspace's order — is
     * already refused by both branches failing.
     */
    public function uploadReceipt(User $user, Order $order): Response
    {
        if ($order->user_id === $user->getKey()) {
            return Response::allow();
        }

        /*
        | 024 · FR-007. The receipt arrived on WhatsApp and the student never
        | opened the product, so the officer who creates the order is the one
        | holding the image. Scoped to a PLATFORM sale on purpose: a course
        | order's receipt is the teacher's business and the platform is not a
        | party to it.
        */
        if ($order->requiresPlatformApproval() && $user->can(Permissions::BILLING_PURCHASE_APPROVE)) {
            return Response::allow();
        }

        return Response::deny('You can only upload receipts for your own orders.');
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
        if (($platform = $this->platformReads($user, $order, 'approve')) !== null) {
            return $platform;
        }

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
        // The condition lives on the enum so the three methods cannot disagree
        // about which kinds it covers; `platformReads()` above is where it is
        // now asked.
        return $user->can(Permissions::PAYMENTS_APPROVE)
            ? Response::allow()
            : Response::deny('You are not authorized to approve payments.');
    }

    public function reject(User $user, Order $order): Response
    {
        // Refusing a platform sale is the platform's call, for the reason
        // approving it is: the money is owed to the platform and the teacher is
        // the payee downstream (spec 014). A teacher who may reject it may cancel
        // a payment made to someone else.
        if (($platform = $this->platformReads($user, $order, 'reject')) !== null) {
            return $platform;
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($order))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::PAYMENTS_REJECT)
            ? Response::allow()
            : Response::deny('You are not authorized to reject payments.');
    }

    /**
     * The platform's answer about a platform sale — or `null` when the question
     * is not the platform's to answer.
     *
     * ⚠️ ASKED **ABOVE** `belongsToCurrentWorkspace()`, AND THAT ORDER IS THE
     * WHOLE POINT OF THIS METHOD EXISTING.
     *
     * `BasePolicy` is explicit that a RESOLVED context which does not match is
     * still a denial, and `WorkspaceContext::id()` falls back to
     * `users.last_workspace_id` for EVERY user — a platform officer included. So
     * a finance officer who also owns a workspace, or once accepted an invitation,
     * had their context resolve to their own workspace and was refused every
     * order outside it: view, approve and reject alike. It stayed invisible only
     * because every fixture builds that officer with no workspace at all, where
     * a null context raises no objection.
     *
     * Spec 024 turns that from rare into the ordinary case: granting across
     * teachers' workspaces IS the work. A course order keeps the check below,
     * where it is the real cross-tenant guard for somebody who is a member.
     *
     * ⚠️ Testing it needs TWO workspaces and an officer WITH `last_workspace_id`.
     * One workspace, or an officer without one, passes green over the defect.
     *
     * @param  'approve'|'reject'|'view'  $ability  the same closed set
     *                                              `OrderKind::platformRefusal()` accepts — a wider `string` here
     *                                              would let a typo reach it and be discovered at runtime.
     */
    private function platformReads(User $user, Order $order, string $ability): ?Response
    {
        if (! $order->requiresPlatformApproval()) {
            return null;
        }

        return $user->can(Permissions::BILLING_PURCHASE_APPROVE)
            ? Response::allow()
            : Response::deny($order->kind->platformRefusal($ability));
    }
}
