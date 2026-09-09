<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Shared\Actions\Action;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Collection;

/**
 * Who this person may pay for — the guardian's half of the picker.
 *
 * ⛔ AND NO SUCH DOOR EXISTED, WHICH IS WHY EVERY SCREEN FILTERED IN TYPESCRIPT.
 * `BillingController::childBalance` REQUIRES `?student=` and answers 403 without
 * it: it reports on a named child and enumerates nobody. So the screens that
 * needed a list fetched `/family/relations` and narrowed it in the browser — and
 * did it differently each time: `ChildSwitcher` filters on `status` and
 * `student_uuid` with **no permission filter at all**, while 029's subscription
 * screen does filter by permission. Two spellings in two files for one question,
 * and neither is the server's.
 *
 * ⚠️ THE ANSWER IS `childrenOf($caller, Payments)` LITERALLY — the same call
 * `PurchaseBeneficiary::resolve()` proves each purchase against, so an option
 * this list offers cannot be refused at the door and one it withholds cannot be
 * bought. Children known by NAME ALONE are absent by construction: the relation
 * carries `student_name` with no `student_user_id` until that child signs up, and
 * there is no account to buy credits for.
 *
 * An empty list is a correct answer and not an error — a student has no children,
 * and a guardian whose only link is still pending has none YET. The screen says
 * which (FR-013); this Action does not have to.
 */
class ListPurchaseBeneficiaries extends Action
{
    public function __construct(private readonly GuardianDirectory $guardians) {}

    /**
     * @return Collection<int, User>
     */
    public function handle(User $caller): Collection
    {
        return $this->guardians->childrenOf($caller, GuardianPermission::Payments);
    }
}
