<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * اعتمادُ المزوّدِ لقالبِ رسالة.
 *
 * ⚠️ الصفوفُ تُزرَعُ `pending` لا `approved`: ادّعاءُ اعتمادٍ لم يحدثْ يجعلُ
 * أوّلَ إرسالٍ رمزَ خطأٍ من المزوّدِ لا يستطيعُ أحدٌ ردَّه إلى صفّه. و`المُصيِّرُ`
 * يرفضُ قالباً غيرَ معتمَد، والإشعارُ يُسقَطُ بصمت.
 */
enum TemplateApprovalStatus: string implements HasArabicLabel
{
    use BuildsOptions;

    case NotRequired = 'not_required';

    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'غير مطلوب',
            self::Pending => 'بانتظار الاعتماد',
            self::Approved => 'معتمَد',
            self::Rejected => 'مرفوض',
        };
    }
}
