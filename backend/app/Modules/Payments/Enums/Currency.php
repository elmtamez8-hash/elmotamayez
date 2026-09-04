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

    /**
     * الرمزُ العربيُّ القصيرُ — للأرقامِ المعروضة.
     *
     * ⚠️ لأنّ سطراً يخلطُ رقماً لاتينيّاً برمزٍ لاتينيٍّ داخلَ نصٍّ عربيٍّ يُعادُ
     * ترتيبُه بصريّاً بقواعدِ الاتّجاهِ ثنائيِّ الجهة، فيقرؤه صاحبُ الشاشةِ
     * مقلوباً — وهو ما أبلغَ عنه صاحبُ المنتَجِ حرفيّاً عن «‏99.98 USD · 480.00
     * QAR». الرمزُ العربيُّ يجعلَ السطرَ كلَّه في اتّجاهٍ واحد.
     */
    public function short(): string
    {
        return match ($this) {
            self::Qar => 'ر.ق',
            self::Usd => 'دولار',
            self::Sar => 'ر.س',
            self::Aed => 'د.إ',
            self::Egp => 'ج.م',
        };
    }
}
