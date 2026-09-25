<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * حالةُ الطلبِ — ليست حالةَ حركةِ الدفع.
 *
 * ⚠️ {@see PaymentStatus} يصفُ ما فعلَه المزوّد؛ هذا يصفُ ما قرّرَه إنسان.
 * توقيعٌ صحيحٌ على إشعارٍ ليس تحقّقاً من المبلغ، ولهذا بقيَ القراران
 * منفصلَين: `approved` هنا هي ما يُنشئُ التسجيل.
 */
enum OrderStatus: string implements HasArabicLabel
{
    use BuildsOptions;

    case Pending = 'pending';

    case UnderReview = 'under_review';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';

    /**
     * Paid, and the money is owed back (spec 011 · store). Written by
     * `RefundStorePurchase` and by `FulfilStorePurchase` when the shelf ran out
     * after payment; left by the officer's «تمّ الردّ» (`SettleRefundDue`),
     * which lands the order on `cancelled`.
     *
     * ⚠️ IT WAS A VALUE IN THE COLUMN FOR MONTHS BEFORE IT WAS A CASE HERE, and
     * so the panel's status filter could not select it and the badge printed
     * the raw string: an instruction to pay a buyer back with no queue behind it.
     */
    case RefundDue = 'refund_due';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار الدفع',
            self::UnderReview => 'قيد المراجعة',
            self::Approved => 'معتمَد',
            self::Rejected => 'مرفوض',
            self::Cancelled => 'ملغى',
            self::RefundDue => 'مستحقّ الاسترداد',
        };
    }
}
