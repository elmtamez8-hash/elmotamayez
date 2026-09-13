<?php

declare(strict_types=1);

namespace App\Shared\Data;

use App\Shared\Contracts\SessionContentAccess;

/**
 * ٠٣٥ — «تُفتَحُ لك هذه الحصّةُ بخصمِ حصّةٍ واحدةٍ من رصيدِك. تقبل؟»
 *
 * ⚠️ IN `App\Shared\Data` AND NOT UNDER Payments, for the reason
 * `SettlementStanding` is (`SettlementClearance.php:8,28`): the type's fully
 * qualified name is written out in a LiveSessions Resource and a Learning
 * payload, and a name under `App\Modules\Payments` there is the wall coming
 * down on a bare string — the scan reads the namespace, not the semantics.
 *
 * ⚠️ COUNTS AND KINDS, NEVER TITLES. A list of the worksheet titles of an hour
 * is a lesson plan for a lesson its owner did not attend; the public-field
 * allowlist already draws that same line on a lesson id, for a reason written
 * beside it.
 *
 * ⚠️ AND NO MONEY IN ANY FIELD. A credit's price is the teacher's approved
 * settlement rate plus two platform constants, so a figure on a student's
 * screen is solvable for that teacher's rate across two package sizes.
 * `StudentBalanceAllowlist` fails the build over one.
 *
 * ⚠️ `purchaseUrl` IS ALWAYS SENT, never only when the balance is empty. A
 * payload whose SHAPE changes with what it found is a distinguishing answer
 * that tells the asker when they are getting warm — the same reason the
 * payment callback and the breach report answer uniformly.
 *
 * @see SessionContentAccess
 */
final class SessionContentOffer extends DataTransferObject
{
    /**
     * @param  int  $credits  what it costs — one, by FR-013's «one consent opens the
     *                        whole hour», never a price per item
     * @param  int  $ownedCredits  the balance as it stands
     * @param  int  $availableCredits  owned MINUS held: what may be committed
     * @param  array<string, int>  $opens  kind ⇒ how many, e.g. `['file' => 3, 'exam' => 1]`
     * @param  string|null  $availableUntil  when the material itself expires (FR-039ب),
     *                                       ISO 8601. Null means «no retention on it».
     */
    public function __construct(
        public readonly int $credits,
        public readonly int $ownedCredits,
        public readonly int $availableCredits,
        public readonly array $opens,
        public readonly ?string $availableUntil,
        public readonly string $purchaseUrl,
    ) {}

    /**
     * ⛔ SNAKE_CASE, BECAUSE THE WIRE IS SNAKE_CASE AND THE BASE IS NOT.
     *
     * `DataTransferObject::toArray()` is `get_object_vars()`, i.e. the PHP
     * property names — and `jsonSerialize()` delegates to it, so BOTH doors
     * this object leaves by (the 422 body's `offer`, and the attribute a
     * Resource stamps) would send `availableCredits` while every other key in
     * the same payload is `available_credits`. The screen reads the snake
     * spelling, so `undefined >= 1` is false: a student holding ten credits is
     * told «لا يكفي», with nothing failing anywhere — `tsc`, pest and vitest
     * are each green over a broken wire. One override, both doors.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'credits' => $this->credits,
            'owned_credits' => $this->ownedCredits,
            'available_credits' => $this->availableCredits,
            'opens' => $this->opens,
            'available_until' => $this->availableUntil,
            'purchase_url' => $this->purchaseUrl,
        ];
    }

    /** Whether pressing the button would succeed — the floor, with held subtracted. */
    public function affordable(): bool
    {
        return $this->availableCredits >= $this->credits;
    }
}
