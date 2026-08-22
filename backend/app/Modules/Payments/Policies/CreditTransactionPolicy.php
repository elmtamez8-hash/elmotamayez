<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * The ledger is readable and nothing else.
 *
 * No update, no delete — not because nobody asked, but because the model throws
 * on both (FR-005). A policy that allowed either would be a promise the model
 * refuses to keep, and the first caller to trust it gets an exception instead of
 * a 403.
 */
class CreditTransactionPolicy extends BasePolicy
{
    public function view(User $user, CreditTransaction $transaction): Response
    {
        // The entry carries no student of its own — the balance does. Asked
        // without the workspace scope and filtered by the student explicitly,
        // for the same reason as CreditBalancePolicy: a student reading their
        // own history is entitled to it in every workspace, not only in the one
        // they happen to be looking at.
        $isOwn = CreditBalance::query()
            ->withoutWorkspaceScope()
            ->whereKey($transaction->credit_balance_id)
            ->where('student_user_id', $user->getKey())
            ->exists();

        if ($isOwn) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($transaction))->denied()) {
            return $workspaceCheck;
        }

        /*
        | ⚠️ ORDERS_VIEW_ALL, NOT BILLING_BALANCE_VIEW — moved by spec 010, and
        | for the same reason as CreditPurchasePolicy beside it. A ledger entry
        | names the purchase that funded it, so the history is the receipts in a
        | list. `billing.balance.view` answers "how many credits, and are they
        | blocked"; it was never meant to answer "what did this family pay".
        */
        return $user->can(Permissions::ORDERS_VIEW_ALL)
            ? Response::allow()
            : Response::deny();
    }

    public function viewAny(User $user): Response
    {
        return Response::allow();
    }
}
