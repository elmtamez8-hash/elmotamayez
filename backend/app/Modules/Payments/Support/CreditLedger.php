<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\ZeroBalanceBehavior;
use App\Modules\Payments\Exceptions\InsufficientCreditsException;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The ledger. Every credit that ever moves, moves through here.
 *
 * Two things live in this class and nowhere else:
 *
 *   1. THE PREDICATE (data-model §5أ). One definition of the floor, of what a
 *      balance can afford, and of what "withheld" means. A second copy is a copy
 *      that drifts, and the two would disagree about the default case first —
 *      `remaining = 0, limit = 0`, which is every student on their first day.
 *
 *   2. THE ORDER (data-model §5ب). Entry first, then lots, then the balance.
 *      Decrementing first means a redelivered event debits twice while the
 *      duplicate entry is ignored once, and the balance stops equalling the sum
 *      of its entries — permanently, and with nothing to notice it.
 *
 * Nothing here dispatches an event. The balance is moved inside a transaction,
 * and an event fired inside one announces a movement that may still roll back.
 * The Actions dispatch, after commit.
 */
class CreditLedger
{
    public function __construct(private readonly BillingSettings $settings) {}

    // ── The predicate ───────────────────────────────────────────────────────

    /**
     * How far below zero this balance may go, as a negative number or zero.
     *
     * An exam-mode window forces it to zero for the same reason prepaid does:
     * during exams nothing is deferred, whatever ceiling the student has earned.
     */
    public function floorFor(CreditBalance $balance, BillingMode $mode, bool $insideExamWindow = false): int
    {
        if (! $mode->allowsDeferral() || $insideExamWindow) {
            return 0;
        }

        return -$balance->credit_limit_credits;
    }

    /**
     * The floor for this balance, reading the mode from settings itself.
     *
     * The form every caller should use. {@see self::floorFor()} takes the mode as
     * an argument, which is right for a unit test and wrong for a call site: a
     * caller that fetches the mode itself is a second place the mode is read, and
     * FR-013 gives that job to BillingSettings alone.
     *
     * The exam window is passed in rather than looked up here, because the one
     * caller that charges a whole session's seats reads it once for the session
     * instead of once per student.
     */
    public function floorForBalance(CreditBalance $balance, bool $insideExamWindow = false): int
    {
        return $this->floorFor($balance, $this->settings->mode($balance->workspace), $insideExamWindow);
    }

    public function canAfford(CreditBalance $balance, int $credits, int $floor): bool
    {
        return $balance->remaining_credits - $credits >= $floor;
    }

    /**
     * Withheld — derived, never stored.
     *
     * "Cannot afford one more credit", which at `remaining = 0, limit = 0` is
     * TRUE. An earlier formulation answered "not withheld" in exactly that case,
     * which is the state a student is in before they have bought anything.
     *
     * FR-027 then splits that refusal in two, and the split is not cosmetic:
     *
     *   · `$floor < 0` — the student had real deferral room and has now used all
     *     of it. That is owing money, and the block is UNCONDITIONAL (US5/3).
     *   · `$floor === 0` — the balance simply ran out, with nothing deferred.
     *     This is the case FR-027 makes configurable: block, remind, or both.
     *
     * Gating the whole predicate on the switch instead would let a workspace set
     * to `remind` carry a student straight past their credit limit, which is the
     * one number the ceiling exists to be.
     *
     * The `remind` HALF of the behaviour is not here and cannot be: a
     * notification with no seeded template is dropped silently, so it lands with
     * its NotificationType and template in US5.
     */
    public function isBlocked(CreditBalance $balance, int $floor, ZeroBalanceBehavior $behavior): bool
    {
        if ($this->canAfford($balance, 1, $floor)) {
            return false;
        }

        if ($floor < 0) {
            return true;
        }

        return $behavior->blocks();
    }

    /**
     * The form every call site should use — the behaviour read from settings.
     *
     * Paired with {@see self::isBlocked()} for the same reason
     * {@see self::floorForBalance()} is paired with {@see self::floorFor()}: the
     * explicit-argument version is what makes the arithmetic testable without a
     * workspace behind it, and the resolving version is what keeps FR-013's "one
     * place reads the billing decision" true at the call sites.
     */
    public function isBlockedForBalance(CreditBalance $balance, int $floor): bool
    {
        return $this->isBlocked(
            $balance,
            $floor,
            $this->settings->zeroBalanceBehavior($balance->workspace),
        );
    }

    // ── The write ───────────────────────────────────────────────────────────

    /**
     * Record one movement. Returns null when it was a duplicate.
     *
     * One transaction per movement — never one per session. A transaction
     * spanning thirty seats makes one student's refusal roll back the other
     * twenty-nine, and holds thirty rows locked across the whole listener.
     *
     * @throws RuntimeException when the entry could not be written and no
     *                          matching entry exists — the insert failed for a
     *                          real reason (a null, a foreign key, a range) that
     *                          insertOrIgnore flattened into "zero rows"
     */
    public function post(CreditMovement $movement): ?CreditTransaction
    {
        return DB::transaction(function () use ($movement): ?CreditTransaction {
            $entry = $this->writeEntry($movement);

            if ($entry === null) {
                return null;
            }

            if ($movement->credits < 0) {
                $this->drawFromLots($entry, -$movement->credits);
            }

            if (! $this->applyToBalance($movement)) {
                // The floor refused it. Undoing the entry we just wrote is the
                // one deletion this ledger performs, and it happens inside the
                // same transaction — so it is a rollback, not an amendment.
                throw new InsufficientCreditsException(
                    'الرصيد لا يكفي لإتمام هذه العملية.',
                );
            }

            if (in_array($movement->type, CreditTransactionType::lotOpening(), true)) {
                $this->openLot($entry, $movement);
            }

            return $entry;
        });
    }

    /**
     * Step 1 and 2 of the order: insertOrIgnore, then read back on zero rows.
     *
     * `uuid` and `created_at` are in the array EXPLICITLY. insertOrIgnore is a
     * Query Builder call, so no model is instantiated and `creating` never fires
     * — HasUuid does not run. On MySQL the resulting NOT NULL violation is
     * downgraded to a warning and `''` is stored, after which every later entry
     * in the entire product collides with that row on `unique(uuid)`, is read as
     * "already recorded", and is silently skipped.
     */
    private function writeEntry(CreditMovement $movement): ?CreditTransaction
    {
        $uuid = (string) Str::uuid();

        $inserted = CreditTransaction::query()->insertOrIgnore([
            'uuid' => $uuid,
            'credit_balance_id' => $movement->balance->getKey(),
            'workspace_id' => $movement->balance->workspace_id,
            'type' => $movement->type->value,
            'credits' => $movement->credits,
            'source_type' => $movement->sourceType,
            'source_id' => $movement->sourceId,
            'performed_by' => $movement->performedBy,
            'reason' => $movement->reason,
            'meta' => $movement->meta === null ? null : json_encode($movement->meta, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        if ($inserted > 0) {
            return CreditTransaction::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
        }

        $existing = $this->findByIdempotencyKey($movement);

        if ($existing !== null) {
            return null;
        }

        throw new RuntimeException(
            'Credit entry was not written and no matching entry exists — insertOrIgnore swallowed a real failure.',
        );
    }

    private function findByIdempotencyKey(CreditMovement $movement): ?CreditTransaction
    {
        $query = CreditTransaction::query()
            ->withoutWorkspaceScope()
            ->where('credit_balance_id', $movement->balance->getKey())
            ->where('type', $movement->type->value)
            ->where('source_type', $movement->sourceType);

        // Null is distinct from null in a unique index on both MySQL and SQLite,
        // so a null source_id never deduplicates anything. The Action mints one
        // from the caller's idempotency key rather than relying on the database.
        $query = $movement->sourceId === null
            ? $query->whereNull('source_id')
            : $query->where('source_id', $movement->sourceId);

        return $query->first();
    }

    /**
     * Step 3: the atomic move. One conditional UPDATE, never a read then a write.
     *
     * `credit_limit_credits` appears as a COLUMN in the condition, never as a
     * bound value: binding it would mean a limit changed concurrently is invisible
     * to the charge, which is the read-then-write this statement exists to remove.
     *
     * ⚠️ CAST(... AS SIGNED) is mandatory and the algebra must not be rearranged.
     * In MySQL one unsigned operand makes the whole result unsigned, so the
     * tempting `remaining + limit >= n` throws ERROR 1690 at `remaining = −3` —
     * a 500 on exactly the students the guard exists to protect, in production
     * only, because SQLite shows nothing.
     *
     * Written as two branches rather than the GREATEST() of the design note:
     * SQLite has no GREATEST, and a statement that only runs on one of the two
     * engines is a statement the test suite cannot exercise.
     */
    private function applyToBalance(CreditMovement $movement): bool
    {
        $query = DB::table('credit_balances')->where('id', $movement->balance->getKey());

        if ($movement->enforceFloor && $movement->credits < 0) {
            $query = $movement->zeroFloor
                ? $query->whereRaw('remaining_credits + ? >= 0', [$movement->credits])
                : $query->whereRaw(
                    'remaining_credits + ? >= -1 * CAST(credit_limit_credits AS SIGNED)',
                    [$movement->credits],
                );
        }

        $delta = $movement->credits;

        // Consumption is the only movement that touches `consumed`. Everything
        // else — a purchase, a bonus, a refund, an expiry, a correction — moves
        // `purchased`, so that remaining = purchased − consumed holds after every
        // one of them. A refund that raised `consumed` would show a student
        // sessions they never took.
        $deltas = ['remaining_credits' => $delta];

        if ($movement->type === CreditTransactionType::Consume) {
            $deltas['consumed_credits'] = -$delta;
        } else {
            $deltas['purchased_credits'] = $delta;
        }

        // incrementEach, not update(): every counter moves RELATIVE to the value
        // in the row, so no value read a moment ago is written back over a
        // concurrent one.
        return $query->incrementEach($deltas, [
            'last_transaction_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }

    /**
     * A batch, for the types that add credits with a life of their own.
     *
     * Only purchases and bonuses open one. A refund or a correction moves the
     * total without being a batch anyone can consume from — giving them lots
     * would let a negative correction be "spent".
     */
    private function openLot(CreditTransaction $entry, CreditMovement $movement): void
    {
        CreditLot::query()->create([
            'credit_transaction_id' => $entry->getKey(),
            'credit_balance_id' => $movement->balance->getKey(),
            'workspace_id' => $movement->balance->workspace_id,
            'credits_total' => $movement->credits,
            'credits_remaining' => $movement->credits,
            'expires_at' => $movement->expiresAt,
        ]);
    }

    /**
     * Take `$needed` credits from the oldest-usable lots (data-model §5ج).
     *
     * Soonest-expiring first, undated last — live from day one even though
     * nothing expires yet, because turning the order on later would change which
     * lot paid for which past consumption, retroactively.
     *
     * One conditional UPDATE per lot: zero rows means someone else emptied it
     * while we looked, and the answer is the next lot, never a re-read and retry.
     * Capped, because the loop is a query per lot against the NFR-012 budget.
     */
    private function drawFromLots(CreditTransaction $entry, int $needed): void
    {
        $lots = CreditLot::query()
            ->withoutWorkspaceScope()
            ->where('credit_balance_id', $entry->credit_balance_id)
            ->where('credits_remaining', '>', 0)
            ->orderByRaw('(expires_at IS NULL), expires_at, id')
            ->limit($this->settings->maxLotsPerDraw())
            ->get();

        foreach ($lots as $lot) {
            if ($needed <= 0) {
                return;
            }

            $take = min($lot->credits_remaining, $needed);

            $claimed = CreditLot::query()
                ->withoutWorkspaceScope()
                ->whereKey($lot->getKey())
                ->where('credits_remaining', '>=', $take)
                ->decrement('credits_remaining', $take);

            if ($claimed === 0) {
                continue;
            }

            // What the draw CLAIMED, not the source of truth. It cannot be
            // derived afterwards: soonest-expiry-first rewrites the answer
            // retroactively every time a sooner-expiring lot arrives.
            DB::table('credit_allocations')->insertOrIgnore([
                'consumed_transaction_id' => $entry->getKey(),
                'lot_transaction_id' => $lot->credit_transaction_id,
                'credits' => $take,
                'created_at' => now(),
            ]);

            $needed -= $take;
        }

        // Falling out with $needed > 0 is not an error: a deferring mode lets a
        // balance go below zero, and below zero there are no lots left to draw.
    }
}
