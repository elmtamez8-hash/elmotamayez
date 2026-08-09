<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CreditBalance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The derived withholding predicate flipped to true for one course.
 *
 * There is no `access_holds` table: withholding is COMPUTED from the balance,
 * the limit, the mode and any exam window, which is what makes FR-033 ("lifted
 * immediately, with no manual intervention") true by construction rather than a
 * chore someone forgets. Same shape as `is_publicly_listed` and the trust score.
 *
 * The cost of deriving it is that the flip has no single write to hang off. Four
 * places can turn it over and only one of them writes a ledger entry:
 *
 *   1. a balance movement (the obvious one),
 *   2. PATCH /manage/billing/students/{student}/limit — the ceiling moved,
 *   3. opening an exam-mode window — the floor is forced to zero,
 *   4. closing one, or its last day passing — the floor comes back.
 *
 * Detecting the change inside the balance Action alone would miss three of the
 * four, and a withheld student would not learn of it until their next financial
 * movement. All four are declared dispatch sites.
 */
class AccessWithheld
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CreditBalance $balance,
    ) {}
}
