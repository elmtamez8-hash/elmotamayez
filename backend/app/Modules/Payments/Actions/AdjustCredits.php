<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Events\BalanceUpdated;
use App\Modules\Payments\Events\RefundIssued;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditTransaction;
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
    public function __construct(private readonly CreditLedger $ledger) {}

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

        $entry = $this->ledger->post(new CreditMovement(
            balance: $balance,
            type: $type,
            credits: $credits,
            sourceType: $this->sourceTypeFor($type),
            sourceId: $this->sourceIdFrom($idempotencyKey),
            performedBy: $performedBy?->getKey() === null ? null : (int) $performedBy->getKey(),
            reason: trim($reason),
        ));

        if ($entry === null) {
            return null;
        }

        DB::afterCommit(function () use ($entry, $balance, $type): void {
            if ($type === CreditTransactionType::Refund) {
                RefundIssued::dispatch($entry);
            }

            BalanceUpdated::dispatch($balance->refresh(), $entry->credits);
        });

        return $entry;
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
