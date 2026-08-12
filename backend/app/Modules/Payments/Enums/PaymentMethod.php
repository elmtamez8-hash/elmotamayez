<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * HOW the money arrived — the missing half of FR-018.
 *
 * The provider says WHO processed it; the method says what the payer actually
 * did, and the two are not the same question: one gateway offers a card
 * redirect and a wallet, and an operator reconciling a bank statement needs the
 * second answer, not the first.
 *
 * It is also the third column of the collection report's index (FR-031). A
 * report that can say what was collected but not how is missing the dimension
 * the money is actually reconciled by.
 */
enum PaymentMethod: string
{
    /** A wire the student makes themselves, confirmed by a human approver. */
    case BankTransfer = 'bank_transfer';

    case MobileWallet = 'mobile_wallet';

    /** A card or local scheme processed by a gateway. */
    case Gateway = 'gateway';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'تحويل بنكي',
            self::MobileWallet => 'محفظة إلكترونية',
            self::Gateway => 'بوابة دفع',
        };
    }
}
