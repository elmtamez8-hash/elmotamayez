<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Payments\Models\CreditBalance;
use App\Shared\Contracts\AccountStanding;
use Illuminate\Database\Eloquent\Collection;

/**
 * The withholding predicate, answered for callers outside Payments.
 *
 * Media refuses a high-value asset with it and LiveSessions refuses a booking
 * with it, and neither ever learns that `credit_balances` exists. Payments owns
 * the balance and binds this implementation; the callers hold the interface.
 *
 * ⚠️ BY COURSE, NEVER BY WORKSPACE. A student who owes for physics and has paid
 * for maths keeps the maths notes. Answering per workspace closes both, which is
 * not a rounding error — it is the wrong answer for a course that is paid up.
 *
 * ⚠️ A COURSE WITH NO BALANCE ROW IS NOT WITHHELD. The account is created lazily,
 * so a student who has never bought anything has no row at all, and reading that
 * absence as "owing" would lock out every student on their first day — including
 * in a workspace that collects by hand and never sells a credit. Withholding is
 * a statement about a balance that exists and has run out.
 */
class EloquentAccountStanding implements AccountStanding
{
    public function __construct(
        private readonly CreditAccounts $accounts,
        private readonly WithholdingReader $withholding,
        private readonly CreditLedger $ledger,
    ) {}

    public function isWithheld(User $student, int $courseId): bool
    {
        return in_array($courseId, $this->withheldCourseIdsFor($student), true);
    }

    /**
     * @return list<int>
     */
    public function withheldCourseIdsFor(User $student): array
    {
        $balances = $this->accounts->balancesFor($student);

        if ($balances->isEmpty()) {
            return [];
        }

        // One read of the mode and the exam window for every workspace in the
        // list, not one per row. The bulk form is the whole reason this method
        // exists: a per-course call inside a loop is the N+1 the contract
        // forbids, and NFR-012 gives the panel a fixed query budget.
        $withheld = $this->withholding->stamp($balances)
            ->filter(fn (CreditBalance $balance): bool => (bool) $balance->getAttribute('is_withheld'))
            ->map(fn (CreditBalance $balance): int => (int) $balance->course_id);

        // array_values on the plain array, not Collection::values(): a Collection
        // keeps its keys through map/filter, and `values()` on it still hands
        // back an array whose list-ness the analyser cannot see.
        return array_values($withheld->all());
    }

    public function creditsNeededFor(User $student, int $courseId): int
    {
        $balance = $this->accounts->balancesFor($student)->firstWhere('course_id', $courseId);

        if ($balance === null) {
            return 0;
        }

        $stamped = $this->withholding->stamp(new Collection([$balance]))->first();

        if ($stamped === null || ! (bool) $stamped->getAttribute('is_withheld')) {
            return 0;
        }

        // What it would take to afford ONE more session: the distance from here
        // up to the floor, plus that session. Never the raw negative balance — a
        // student with a ceiling owes less than their balance reads, and quoting
        // the deficit would ask them for credits they do not need.
        return max(1, $this->ledger->floorForBalance($stamped) + 1 - $stamped->remaining_credits);
    }
}
