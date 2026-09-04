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
 * الشاشةُ تجمعُ كلَّ المساحاتِ معاً، فحراستُها بالاسمِ الثاني تُسلِّمُ مساعدَ مدرّسٍ
 * أرقامَ كلِّ منافسٍ له. {@see PlatformAnalytics}
 * كتبَت القاعدةَ نفسَها.
 *
 * ⚠️ **وعلى الويدجتِ لا على الصفحةِ وحدَها.** صنفُ الويدجت قابلٌ للتصييرِ
 * مستقلّاً عن الصفحةِ التي تستضيفُه، فبابٌ على الصفحةِ فقط بابٌ واحدٌ لغرفةٍ لها
 * مدخلان. ولذلك أيضاً **لا يُضافُ أيٌّ من هذه إلى `->widgets([])` في
 * `AdminPanelProvider`**: تلك القائمةُ تُصيَّرُ على لوحةِ `/admin` المشتركة، وكلُّ
 * مدرّسٍ يصلُها.
 */
trait PlatformWideWidget
{
    public static function canView(): bool
    {
        return Auth::user()?->can(Permissions::ANALYTICS_CROSS_TEACHER_VIEW) ?? false;
    }
}
