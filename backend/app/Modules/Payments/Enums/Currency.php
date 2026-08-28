<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * العملاتُ المعروضةُ في اللوحة.
 *
 * ⚠️ مجموعةٌ مغلقةٌ في النوعِ لا جدولٌ في قاعدةِ البيانات: عملةٌ جديدةٌ ليست
 * صفّاً يُدخِلُه مشغِّلٌ — إنّها سعرٌ وتسويةٌ وتقريبٌ وتقريرُ تحصيل، وكلُّها
 * كودٌ يُكتَب. ولو كان جدولاً لأمكنَ لمشغِّلٍ أن يبيعَ بعملةٍ لا يعرفُ
 * `config('billing.currency')` كيفَ يُسوّيها.
 *
 * ⚠️ والافتراضُ في الهجرةِ الأصليّةِ `USD` — بقيّةُ هيكلِ Laravel — بينما
 * عملةُ المنتَجِ `QAR`. لذلك يقرأُ النموذجُ `config('billing.currency')`
 * ولا يكتبُ حرفاً.
 */
enum Currency: string implements HasArabicLabel
{
    use BuildsOptions;

    case Qar = 'QAR';

    case Usd = 'USD';

    case Sar = 'SAR';

    case Aed = 'AED';

    case Egp = 'EGP';

    public function label(): string
    {
        return match ($this) {
            self::Qar => 'ريال قطري (QAR)',
            self::Usd => 'دولار أمريكي (USD)',
            self::Sar => 'ريال سعودي (SAR)',
            self::Aed => 'درهم إماراتي (AED)',
            self::Egp => 'جنيه مصري (EGP)',
        };
    }
}
