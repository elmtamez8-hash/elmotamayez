<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\PaymentTransaction;

/**
 * Who may read a payment.
 *
 * ⚠️ OWNERSHIP OF THE ORDER, AND NOTHING ELSE. Not a workspace permission, not
 * membership — those all pass for a teacher, and `BelongsToWorkspace` already
 * lets one through the only automatic filter there is. Without this policy a
 * teacher opens `/payments/{transaction}` and reads the cost-plus total a named
 * student paid for credits, which is exactly what FR-033 forbids.
 *
 * `PaymentTransaction` carries no `user_id`; ownership is one hop through the
 * order, and it is the hop that gets forgotten.
 */
class PaymentTransactionPolicy
{
    public function view(User $user, PaymentTransaction $transaction): bool
    {
        return $transaction->order?->user_id === $user->getKey();
    }
}
