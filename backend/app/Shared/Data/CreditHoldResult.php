<?php

declare(strict_types=1);

namespace App\Shared\Data;

use App\Shared\Contracts\SessionCreditHolds;

/**
 * ٠٣٥ — جوابُ «هل يكفي رصيدُك لحجزِ هذه الحصّة؟».
 *
 * ⚠️ A REFUSAL IS AN ANSWER, NEVER AN EXCEPTION. `BookSeat` calls the hold
 * inside its own transaction so the seat is handed back if the credit is
 * refused — and it may not so much as NAME `Payments\Exceptions\
 * InsufficientCreditsException` to catch one: the whole of LiveSessions'
 * allowance is a single literal import checked by
 * `ContextIsolationTest:685`. It reads this object and raises its OWN
 * `DomainException`, which is what rolls the outer transaction back.
 *
 * ⚠️ `firstReleaseAt` IS THE SENTENCE, not decoration. «لا رصيد» with no date
 * is a refusal a student can do nothing with; «يُفرَجُ أوّلُ حجزٍ يومَ كذا» is
 * the same refusal with a way out — and US2's second scenario is that sentence.
 * Null means there is no live hold to wait for, i.e. buying is the only road.
 *
 * @see SessionCreditHolds
 */
final class CreditHoldResult extends DataTransferObject
{
    public function __construct(
        public readonly bool $granted,
        public readonly int $availableCredits = 0,
        public readonly ?string $firstReleaseAt = null,
    ) {}

    public static function refused(int $availableCredits, ?string $firstReleaseAt): self
    {
        return new self(granted: false, availableCredits: $availableCredits, firstReleaseAt: $firstReleaseAt);
    }
}
