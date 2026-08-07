<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Models\User;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Events\TeacherPayoutIssued;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeacherPayout;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * Money leaves. Once per period, never negative.
 *
 * Two guarantees, guarded in two different places on purpose:
 *
 *   Paying twice is refused by the UNIQUE index on `settlement_period_id`. A
 *   check inside this Action would protect the API path and nothing else, and
 *   the settlement cycle is exactly the kind of thing an operator re-runs.
 *
 *   Paying a negative is refused by the COLUMN, which is unsigned. A shortfall
 *   is carried into the next window (FR-026), and the schema is what makes
 *   "carried, never paid" a fact rather than a rule someone maintains.
 *
 * The Action still refuses both up front — a clear Arabic message beats an
 * integrity-constraint stack trace — but neither refusal is the guarantee.
 */
class RecordTeacherPayout extends Action
{
    public function __construct(
        private readonly WriteLedgerEntry $ledger,
    ) {}

    /** @return TeacherPayout|null the payout, or null if this period was already paid */
    public function handle(
        SettlementPeriod $period,
        User $by,
        ?string $reference = null,
        ?string $method = null,
    ): ?TeacherPayout {
        // Already paid: a no-op, not an error. FR-028 asks the cycle to be
        // harmless on re-run, and "this period was paid last night" is the
        // expected outcome of running it again — not a failure an operator has
        // to interpret. This is a fast path, not the guarantee: the unique index
        // below is what holds when two runners arrive at once.
        if ($this->existingPayout($period) !== null) {
            return null;
        }

        if ($period->status !== SettlementPeriodStatus::Closed) {
            throw new DomainException('لا يُصرَف إلا عن فترة مغلقة.');
        }

        if ($period->net_minor <= 0) {
            throw new DomainException('لا صافي مستحقّاً في هذه الفترة. الرصيد السالب يُرحَّل إلى الفترة التالية.');
        }

        try {
            $payout = TeacherPayout::query()->create([
                'workspace_id' => (int) $period->workspace_id,
                'teacher_profile_id' => (int) $period->teacher_profile_id,
                'settlement_period_id' => (int) $period->getKey(),
                'amount_minor' => $period->net_minor,
                'currency' => (string) $period->currency,
                'reference' => $reference,
                'method' => $method,
                'executed_at' => now(),
                'executed_by' => $by->getKey(),
            ]);
        } catch (QueryException $e) {
            // The unique index did its job: someone else paid this period, or the
            // cycle ran twice. Distinguishing "already paid" from a real database
            // failure by inspecting driver codes would be a per-driver guess, so
            // re-read instead: a payout exists ⇒ the constraint fired.
            if ($this->existingPayout($period) !== null) {
                return null;
            }

            throw $e;
        }

        // Negative, so the running balance falls to zero rather than leaving the
        // teacher owed what they were just paid. Stamped with the period, so the
        // open window's total does not see it.
        $this->ledger->handle(
            workspaceId: (int) $period->workspace_id,
            teacherProfileId: (int) $period->teacher_profile_id,
            type: LedgerEntryType::Payout,
            amountMinor: -$payout->amount_minor,
            currency: (string) $payout->currency,
            settlementPeriodId: (int) $period->getKey(),
            payoutId: (int) $payout->getKey(),
            reason: $reference,
            createdBy: $by->getKey(),
        );

        // Conditional, like the close: the state machine only ever moves forward,
        // and the payout row above is what already made the money idempotent.
        SettlementPeriod::query()
            ->whereKey($period->getKey())
            ->where('status', SettlementPeriodStatus::Closed->value)
            ->update(['status' => SettlementPeriodStatus::Paid->value]);

        TeacherPayoutIssued::dispatch($payout);

        return $payout;
    }

    /**
     * Has this period already been paid?
     *
     * Marked impure because it genuinely is: the answer can change between two
     * calls in the same method, and this class asks twice ON PURPOSE — once as a
     * fast path, and once after the insert failed, when the whole question is
     * whether somebody else answered it differently in between. Treating it as
     * pure would let the analyser conclude the second check is dead code, which
     * is exactly the race it exists for.
     *
     * @phpstan-impure
     */
    private function existingPayout(SettlementPeriod $period): ?TeacherPayout
    {
        return TeacherPayout::query()
            ->where('settlement_period_id', $period->getKey())
            ->first();
    }
}
