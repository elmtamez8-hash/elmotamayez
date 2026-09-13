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
        /**
         * Whether a negative movement should take its credits out of the lots.
         *
         * True for every movement but one. An EXPIRY has already emptied the
         * exact lot it is writing off — with the conditional UPDATE that makes
         * the claim safe — so letting the drawer run again would take the same
         * credits a second time, out of lots that have not expired at all.
         */
        public readonly bool $drawsFromLots = true,
        /**
         * ٠٣٥ — whether the floor must also subtract what is FROZEN.
         *
         * ⛔ TRUE FOR EXACTLY ONE MOVEMENT: the student spending a credit to
         * open a session they did not sit in. That is a NEW voluntary
         * commitment, and a student whose remaining credit is already frozen
         * against a seat they booked must not be able to spend it twice.
         *
         * ⚠️ AND FALSE EVERYWHERE ELSE, WHICH IS THE HALF THAT MATTERS. It is
         * read INSIDE the `enforceFloor` branch of `applyToBalance()` and
         * nowhere near `canAfford()`, `isBlocked()` or `floorFor()` — those
         * three are the single spelling of «can this student pay», and
         * `isBlocked()` calls `canAfford()` directly. Subtract the held credits
         * there and a student with one credit who books one session reads as
         * DEFAULTED: locked out of the room their booking bought, and out of
         * every lesson in a course they have paid for.
         *
         * ⚠️ AND `applyToBalance()` IS ALSO THE REFUND PATH (`AdjustCredits`
         * is the other producer of `enforceFloor: true`). A refund is not a new
         * commitment, so it does not subtract the held credits either.
         */
        public readonly bool $subtractHeld = false,
    ) {}
}
