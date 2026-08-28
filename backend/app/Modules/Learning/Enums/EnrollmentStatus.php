<?php

declare(strict_types=1);

namespace App\Modules\Learning\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * حالةُ تسجيلِ طالبٍ في مقرّر.
 *
 * ⚠️ عمودٌ نصّيٌّ في قاعدةِ البيانات (`enrollments.status`, افتراضُه `active`)
 * ولا تحويلَ عليه في النموذج، فهذا النوعُ مجموعةُ القيمِ المعروضةُ لا قيدٌ
 * على الكتابة. الحارسُ على الكتابةِ هو الإجراءُ الذي يُغيِّرُ الحالة.
 */
enum EnrollmentStatus: string implements HasArabicLabel
{
    use BuildsOptions;

    case Active = 'active';

    case Completed = 'completed';

    case Expired = 'expired';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشِط',
            self::Completed => 'مكتمل',
            self::Expired => 'منتهٍ',
            self::Cancelled => 'ملغى',
        };
    }
}
