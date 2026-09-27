<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Events\CreditExpired;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Support\CreditLedger;
use App\Shared\Traits\RunsAlone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Lots whose day has passed, written off — and SWITCHED OFF at launch (Q-5).
 *
 * ⚠️ IT FINDS NOTHING TODAY, BY DESIGN AND NOT BY OVERSIGHT.
 * `credit_packages.validity_days` defaults to null, `CreditLot::expires_at`
 * follows it, and this job selects on a dated lot — so a platform that never sets
 * a validity has nothing here to act on. It is built now for the reason
 * {@see CreditExpired} was: switching expiry on afterwards would be a migration
 * over credits people bought on the understanding that they were permanent, and
 * the consumption order it depends on (soonest-expiring first) has been live
 * since day one so that turning it on cannot rewrite which lot paid for which
 * past session.
 *
 * ⚠️ THE WRITE-OFF GOES THROUGH THE LEDGER, never straight at the balance. The
 * remaining counter, the entry and the audit trail are one movement — an UPDATE
 * on `credit_balances` here would leave the balance disagreeing with the sum of
 * its own entries, which is exactly what {@see ReconcileCreditBalancesJob} would
 * then report every night.
 *
 * Safe to run twice: the lot is emptied by a conditional UPDATE that claims the
 * exact remainder it read, and the entry carries the lot id as its idempotency
 * key. A second pass over an expired lot claims nothing and writes nothing.
 */
class ExpireCreditLotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsAlone, SerializesModels;

    public function handle(CreditLedger $ledger): void
    {
        CreditLot::query()
            ->withoutWorkspaceScope()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('credits_remaining', '>', 0)
            ->chunkById(200, function (iterable $lots) use ($ledger): void {
                foreach ($lots as $lot) {
                    $this->expire($lot, $ledger);
                }
            });
    }

    /**
     * The first write-off of a lot keeps the key it always had; any later one
     * (the part that was held for a seat, freed since) is numbered.
     */
    public const SOURCE_TYPE = 'credit_lot';

    private function expire(CreditLot $lot, CreditLedger $ledger): void
    {
        $remainder = $lot->credits_remaining;

        $balance = CreditBalance::query()->withoutWorkspaceScope()->find($lot->credit_balance_id);

        if ($balance === null) {
            return;
        }

        /*
        | ⛔ NEVER THE PART THAT IS HOLDING A SEAT (audit 2026-09-27). A credit
        | frozen for next Tuesday's booking is still in `remaining_credits`, and
        | it may well be sitting in this lot. Writing the whole lot off took it:
        | the seat stayed booked, Tuesday's charge then drew on a balance that no
        | longer had the credit, and the student ended the lesson NEGATIVE — in
        | arrears, withheld, for a lesson they had paid for before the date.
        |
        | So only what is FREE may expire: `remaining − held` on the balance,
        | never more than this lot holds. The rest stays in the lot, which the
        | drawer takes first (soonest expiry first — an expired lot is the
        | soonest of all), so the held seat is charged out of exactly the credit
        | that would otherwise have lapsed. If the seat is released instead, the
        | credit is free again and the next night's run writes it off.
        */
        $free = max(0, (int) $balance->remaining_credits - (int) $balance->held_credits);
        $expiring = min($remainder, $free);

        if ($expiring <= 0) {
            return;
        }

        // The claim, in the shape a seat is claimed: one conditional UPDATE that
        // both checks and takes. `count() then update()` is the race itself — a
        // consumption landing between the two would be paid for out of a lot this
        // job has already decided to write off, and the student would lose a
        // credit they had just spent.
        $claimed = CreditLot::query()
            ->withoutWorkspaceScope()
            ->whereKey($lot->getKey())
            ->where('credits_remaining', $remainder)
            ->update(['credits_remaining' => $remainder - $expiring]);

        if ($claimed === 0) {
            return;
        }

        $entry = $ledger->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Expire,
            credits: -$expiring,
            /*
            | The idempotency key: one write-off per lot PER PASS. A lot now can
            | lapse in parts (the held part stays until its seat is settled), and
            | a second part under the first part's key would be read as «already
            | recorded» — the lot emptied with no entry behind it, and the nightly
            | `lot_remainder` invariant reporting it for ever. The claim above is
            | what makes the pass number stable: only its winner gets here.
            */
            sourceType: $this->sourceTypeFor($lot),
            sourceId: (int) $lot->getKey(),
            reason: 'انتهت صلاحية دفعة أرصدة.',
            // The lot above was emptied by the claim. Letting the drawer run
            // would take the same credits again, out of lots that have not
            // expired — the student would lose twice what ran out.
            drawsFromLots: false,
        ));

        if ($entry !== null) {
            // After the transaction, for the reason nothing in the ledger
            // dispatches: an event fired inside one announces a movement that may
            // still roll back.
            DB::afterCommit(fn () => CreditExpired::dispatch($entry));
        }
    }

    /**
     * `credit_lot` for a lot's first write-off (every entry written before
     * 2026-09-27 carries it), `credit_lot_2`, `credit_lot_3`… for the parts
     * after it — well inside the 32 characters `source_type` allows.
     */
    private function sourceTypeFor(CreditLot $lot): string
    {
        $earlier = CreditTransaction::query()
            ->withoutWorkspaceScope()
            ->where('credit_balance_id', $lot->credit_balance_id)
            ->where('type', CreditTransactionType::Expire->value)
            ->where('source_id', $lot->getKey())
            // A plain prefix: an escaped `\_` means different things to the two
            // engines, and no other Expire entry is keyed on a lot id.
            ->where('source_type', 'like', self::SOURCE_TYPE.'%')
            ->count();

        return $earlier === 0 ? self::SOURCE_TYPE : self::SOURCE_TYPE.'_'.($earlier + 1);
    }
}
