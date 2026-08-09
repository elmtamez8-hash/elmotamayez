<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CreditTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lot expired and its remainder was written off.
 *
 * BUILT AND SWITCHED OFF. `credit_packages.validity_days` defaults to null,
 * meaning "never expires", which is the launch policy (Q-5) — so nothing
 * dispatches this today.
 *
 * It exists now rather than later because the alternative is worse: turning
 * expiry on after launch would be a migration over credits people bought on the
 * understanding that they were permanent. The structure is ready and the policy
 * is off. The consumption order that goes with it — soonest-expiring first — is
 * live from day one for the same reason.
 */
class CreditExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CreditTransaction $transaction,
    ) {}
}
