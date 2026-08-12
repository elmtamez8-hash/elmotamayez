<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * What became of one provider notification.
 *
 * Stored, never derived: "why did this callback change nothing" is a question
 * asked days later by someone holding a provider's reference and a customer
 * complaint, and a reason recomputed from the current state answers about today
 * rather than about the moment.
 */
enum CallbackResult: string
{
    /** Applied — the transaction moved. */
    case Accepted = 'accepted';

    /** Refused at the door (NFR-011). The body was never parsed. */
    case RejectedSignature = 'rejected_signature';

    /** Already applied under this provider's event id. */
    case Duplicate = 'duplicate';

    /** Arrived before the transaction it names existed; queued to retry. */
    case Deferred = 'deferred';

    /** Signed, but the amount or currency was not ours. */
    case Mismatch = 'mismatch';

    /** Retried to the declared limit and given up on (FR-017). */
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'مقبول',
            self::RejectedSignature => 'توقيع غير صالح',
            self::Duplicate => 'مكرَّر',
            self::Deferred => 'مؤجَّل',
            self::Mismatch => 'مبلغ غير مطابق',
            self::Abandoned => 'مهجور',
        };
    }
}
