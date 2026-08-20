<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Exceptions;

use RuntimeException;

/**
 * A redemption the shop would not take (FR-033).
 *
 * ⚠️ THE REASON IS DERIVED AFTER THE REFUSAL, BY A DIAGNOSTIC QUERY — never by
 * checking the conditions before the claim. Checking first turns the guard back
 * into a read followed by a write, which is exactly what the single conditional
 * statement exists to replace: two students on the last unit would both pass the
 * check and both be told yes.
 *
 * So the order is: try, fail, and only then ask why — at which point the answer
 * costs one query and is allowed to be slightly stale, because it is a sentence
 * for a human rather than a decision.
 */
class RedemptionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function outOfStock(): self
    {
        return new self('out_of_stock', 'نفد مخزون هذه المكافأة.');
    }

    public static function monthlyCapReached(): self
    {
        return new self('monthly_cap_reached', 'بلغت هذه المكافأة سقفها الشهري. جرّب في الشهر القادم.');
    }

    public static function inactive(): self
    {
        return new self('inactive', 'هذه المكافأة لم تعد متاحة.');
    }

    public static function insufficientCoins(): self
    {
        return new self('insufficient_coins', 'عملاتك عند هذا المدرّس لا تكفي لهذه المكافأة.');
    }
}
