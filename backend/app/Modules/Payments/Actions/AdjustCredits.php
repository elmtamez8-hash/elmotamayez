<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Events\RefundIssued;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Support\BalanceAnnouncer;
use App\Modules\Payments\Support\CreditLedger;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Bonus, correction and refund — every movement with no payment leg.
 *
 * The reason is MANDATORY and enforced here, not in a FormRequest: the Filament
 * panel and any future console command reach the same Action, and a rule that
 * only exists on the HTTP path is a rule with a door beside it.
 *
 * ⚠️ A refund is keyed by its OWN identity (`source_type = credit_refund`), not
 * by the purchase it reverses. Keyed by the purchase, a second partial refund of
 * the same purchase collides with the first on the idempotency index, is read as
 * a duplicate, and is reported as a success while returning nothing.
 */
class AdjustCredits extends Action
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly BalanceAnnouncer $announcer,
    ) {}

    /**
     * @param  int  $credits  signed — positive adds, negative removes
     * @param  string  $idempotencyKey  the caller's own key; two taps on one
     *                                  button carry the same one and produce one
     *                                  entry
     */
    public function handle(
        CreditBalance $balance,
        CreditTransactionType $type,
        int $credits,
        string $reason,
        string $idempotencyKey,
        ?User $performedBy = null,
    ): ?CreditTransaction {
        if (trim($reason) === '') {
            throw new DomainException('السبب إلزامي لكل تسوية أو منحة أو استرداد.');
        }

        if ($credits === 0) {
            throw new DomainException('لا يمكن تقييد حركة بصفر رصيد.');
        }

        if ($type === CreditTransactionType::Refund && $credits < 0) {
            $credits = $this->refundable($balance, $credits);
        }

        $wasBlocked = $this->announcer->isBlocked($balance);

        $entry = $this->ledger->post(new CreditMovement(
            balance: $balance,
            type: $type,
            credits: $credits,
            sourceType: $this->sourceTypeFor($type),
            sourceId: $this->sourceIdFrom($idempotencyKey),
            performedBy: $performedBy?->getKey() === null ? null : (int) $performedBy->getKey(),
            reason: trim($reason),
            // ⚠️ THE ONE PLACE IN THIS PHASE THE FLOOR IS ENFORCED, and the
            // asymmetry with delivery is the whole design. A delivered session is
            // a debt that happened whether or not the student can pay it, so
            // recording it must never be refused. A refund is money going OUT —
            // and money paid out against credits that were already consumed is a
            // session the teacher delivered and would now be unpaid for.
            enforceFloor: $type === CreditTransactionType::Refund,
        ));

        if ($entry === null) {
            return null;
        }

        DB::afterCommit(function () use ($entry, $balance, $type, $wasBlocked): void {
            if ($type === CreditTransactionType::Refund) {
                RefundIssued::dispatch($entry);
            }

            $this->announcer->announce($balance, $wasBlocked, $entry->credits);
        });

        return $entry;
    }

    /**
     * How much of a requested refund there is actually left to give back.
     *
     * ⚠️ CLAMPED, NOT REFUSED. Quickstart 9ج: a student holding 6 credits asks
     * for 8 back, and 6 is what returns — the other two bought sessions that were
     * delivered, and the teacher has already earned their fee for them (spec
     * 014). Refusing the whole request would hold hostage the credits nobody
     * disputes; paying all eight would buy back a delivery that happened.
     *
     * ⚠️ AND THE FLOOR IS STILL ENFORCED ON TOP OF THIS. The clamp reads a number
     * and the ledger writes with a conditional UPDATE, so a session charged in
     * between would make this figure stale — the statement then affects zero rows
     * and an honest exception is raised. Two guards for one rule is not
     * duplication here: this one decides the AMOUNT, that one decides whether the
     * amount is still true at the moment of the write.
     *
     * @param  int  $credits  negative, as every refund is
     * @return int negative, and never deeper than the balance holds
     */
    private function refundable(CreditBalance $balance, int $credits): int
    {
        $remaining = (int) CreditBalance::query()
            ->withoutWorkspaceScope()
            ->whereKey($balance->getKey())
            ->value('remaining_credits');

        $available = max(0, $remaining);

        if ($available === 0) {
            // Distinct from the "zero credits" refusal above, which reads as a
            // caller mistake. This one is a fact about the account, and the
            // operator needs to be told which it is.
            throw new DomainException('لا يوجد رصيد قابل للاسترداد على هذا الحساب.');
        }

        return -min(abs($credits), $available);
    }

    private function sourceTypeFor(CreditTransactionType $type): string
    {
        return match ($type) {
            CreditTransactionType::Refund => 'credit_refund',
            CreditTransactionType::Bonus => 'credit_bonus',
            default => 'credit_adjustment',
        };
    }

    /**
     * A stable integer for the caller's key.
     *
     * The idempotency guard has to live in `source_id`, which is an integer
     * column — and NULL is distinct from NULL in a unique index on both MySQL
     * and SQLite, so leaving it null deduplicates nothing at all. Two taps on
     * "grant a bonus" would be two bonuses.
     *
     * Fifteen hex digits of SHA-256: 60 bits, always positive, and far enough
     * from a collision that the first one will not happen. crc32 would fit the
     * column too, and would silently drop a legitimate second adjustment on a
     * 32-bit collision.
     */
    private function sourceIdFrom(string $idempotencyKey): int
    {
        return (int) hexdec(substr(hash('sha256', $idempotencyKey), 0, 15));
    }
}
