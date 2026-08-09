<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditBalance;
use App\Shared\Data\DataTransferObject;
use DateTimeInterface;

/**
 * One movement of credits, described completely before anything is written.
 *
 * `credits` is SIGNED — positive adds, negative removes — and the sign is not
 * derived from the type, because `adjustment` has to be able to go either way
 * (see {@see CreditTransactionType}).
 *
 * `enforceFloor` is false by default, and that default is the important one:
 * the floor guards BOOKING, not the recording of a debt already incurred. A
 * session that was delivered is a debt whether or not the student can pay it —
 * spec 014 has already earned the teacher their fee from the same event, so
 * refusing to write the entry would leave the platform owing money with no claim
 * against anyone (data-model §5هـ).
 */
class CreditMovement extends DataTransferObject
{
    /** @param array<string, mixed>|null $meta */
    public function __construct(
        public readonly CreditBalance $balance,
        public readonly CreditTransactionType $type,
        public readonly int $credits,
        public readonly string $sourceType,
        public readonly ?int $sourceId = null,
        public readonly ?int $performedBy = null,
        public readonly ?string $reason = null,
        public readonly ?array $meta = null,
        public readonly bool $enforceFloor = false,
        /** Zero floor — prepaid mode, or an open exam-mode window. */
        public readonly bool $zeroFloor = true,
        /** Null means "never expires", which is the launch default (Q-5). */
        public readonly ?DateTimeInterface $expiresAt = null,
    ) {}
}
