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

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار الدفع',
            self::UnderReview => 'قيد المراجعة',
            self::Approved => 'معتمَد',
            self::Rejected => 'مرفوض',
            self::Cancelled => 'ملغى',
        };
    }
}
