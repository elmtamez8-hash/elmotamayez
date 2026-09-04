<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets\Concerns;

use App\Modules\Analytics\Filament\Pages\PlatformAnalytics;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Support\Facades\Auth;

/**
 * البابُ الذي يحملُه كلُّ ويدجت من نفسِه.
 *
 * ⚠️ `analytics.cross_teacher.view` لا `analytics.view`: الثانيةُ صلاحيّةُ
 * **مساحة** وهي في مصفوفةِ المساعد، وتجيبُ عن «أتَرى أرقامَ مدرّسِك». وهذه
 * الويدجتاتُ تجمعُ كلَّ المساحاتِ معاً، فحراستُها بالاسمِ الثاني تُسلِّمُ مساعدَ
 * مدرّسٍ أرقامَ كلِّ منافسٍ له.
 * {@see PlatformAnalytics} كتبَت
 * القاعدةَ نفسَها.
 *
 * ⚠️ **وهذا البابُ هو ما يجعلُ التسجيلَ على لوحةِ `/admin` المشتركةِ آمناً.**
 * `Page::filterVisibleWidgets()` سطرٌ واحدٌ: `array_filter(... ::canView())` —
 * يُنفَّذُ قبلَ التصييرِ على كلِّ ويدجتٍ في `->widgets([])`. فالمدرّسُ الذي يصلُ
 * الشاشةَ لا يرى منها شيئاً، ويبقى له الويدجتانِ القديمانِ اللذانِ يقرآنِ مساحتَه
 * وحدَها.
 *
 * ⚠️ **وشُحِنَ عكسُ ذلك أوّلَ مرّةٍ**: صفحةٌ منفصلةٌ `/admin/insights` وتعليقٌ
 * يقولُ «لا يُسجَّلُ أيٌّ من هذه في `->widgets([])`». كان احتياطاً طبقةً زائدةً
 * مبنيّاً على افتراضٍ لم يُقَسْ — أنّ القائمةَ تُصيَّرُ بلا ترشيح — وثمنُه شاشةٌ
 * ثانيةٌ لا يجدُها أحدٌ ولوحةٌ رئيسيّةٌ فارغةٌ لمن يملكُ رؤيةَ كلِّ شيء. الحارسُ
 * هو `canView()`، لا مكانُ التسجيل.
 */
trait PlatformWideWidget
{
    public static function canView(): bool
    {
        return Auth::user()?->can(Permissions::ANALYTICS_CROSS_TEACHER_VIEW) ?? false;
    }
}
