<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Shared\Data\DataTransferObject;

/**
 * What one package costs on one course, at one moment.
 *
 * ⚠️ ALL FOUR AMOUNTS ARE FOR THE WHOLE PURCHASE, not per credit, and the
 * invariant is that the first three ADD UP to the fourth. `teacher_rate_minor`
 * reads like a per-unit rate and the per-unit figure is recoverable — `credits`
 * is stored beside it in `credit_purchases`. The reverse convention is not: with
 * two columns per credit and two per purchase, the four no longer sum, and every
 * reader has to remember which two to multiply before it balances. An identity a
 * test can assert beats a convention a reader must carry.
 *
 * Spec 015 generates its books from these columns retroactively (Q-2), and a set
 * of numbers that does not add up is a set of books that does not either.
 */
class PackagePrice extends DataTransferObject
{
    public function __construct(
        public readonly int $credits,
        /** The teacher's approved rate × credits — what settlement will owe. */
        public readonly int $teacherRateMinor,
        /** The platform's fixed per-session fee × credits. */
        public readonly int $operatingFeeMinor,
        /** What the gateway keeps out of `totalMinor`. */
        public readonly int $gatewayFeeMinor,
        /** What the student is charged. */
        public readonly int $totalMinor,
        public readonly string $currency,
    ) {}
}
