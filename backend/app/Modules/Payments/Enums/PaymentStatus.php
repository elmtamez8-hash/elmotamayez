<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Where a payment transaction stands — a CLOSED list, replacing a free string.
 *
 *   Initiated ──► Pending ──┬─► Captured ──► Reversed   (bank dispute)
 *                           ├─► Failed
 *                           ├─► Expired                 (timed out — FR-015)
 *                           └─► Mismatch                (amount or currency differed)
 *
 * A free string closes no path: every reader has to guess the vocabulary, and a
 * typo is a status nothing matches and nothing rejects.
 *
 * ⚠️ CAPTURED IS FINAL EXCEPT THROUGH REVERSED, and there is no automatic route
 * back INTO it. FR-014 forbids an automatic correction in the direction that
 * harms the student, so `Failed → Captured` needs a human. The opposite
 * direction — `Pending → Captured` when reconciliation finds the payment
 * succeeded — is automatic by right, because it serves the student.
 *
 * ⚠️ AND `Reversed → Captured` DOES NOT EXIST AT ALL. A gateway that mints a
 * fresh identifier for every resend would otherwise walk a reversed payment back
 * to captured — past every unique index, since the identifier is new — and lift
 * a withholding that a bank dispute had just imposed. A reversal is undone by a
 * new payment, not by rewinding the old one.
 */
enum PaymentStatus: string
{
    /** Created on our side; the provider has not been asked yet. */
    case Initiated = 'initiated';

    /** With the provider, awaiting an outcome. */
    case Pending = 'pending';

    case Captured = 'captured';

    case Failed = 'failed';

    /** Closed by the sweep after the declared timeout (FR-015). */
    case Expired = 'expired';

    /** The provider confirmed a payment whose amount or currency was not ours. */
    case Mismatch = 'mismatch';

    /** Captured, then taken back — a chargeback or a bank reversal. */
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'بدأت',
            self::Pending => 'قيد المعالجة',
            self::Captured => 'محصَّلة',
            self::Failed => 'فشلت',
            self::Expired => 'انتهت مهلتها',
            self::Mismatch => 'مبلغ غير مطابق',
            self::Reversed => 'مُعادة',
        };
    }

    /**
     * Nothing follows these. A transaction here is answered from the row, and no
     * callback, sweep or retry may move it.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Initiated, self::Pending => false,
            default => true,
        };
    }

    /**
     * The whole transition table, in one place.
     *
     * ⚠️ Asked BEFORE a write, never after: a status compared to a constant in
     * five Actions is five copies of this table, and the fifth one is where a
     * `Failed → Captured` slips in.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Initiated => [self::Pending, self::Failed, self::Expired],
            self::Pending => [self::Captured, self::Failed, self::Expired, self::Mismatch],
            self::Captured => [self::Reversed],
            // Terminal. Reversed included: a reversed payment is replaced by a
            // new transaction, never walked back to captured.
            self::Failed, self::Expired, self::Mismatch, self::Reversed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
