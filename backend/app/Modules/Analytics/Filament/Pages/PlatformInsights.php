<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Pages;

use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Analytics\Filament\Widgets\GrowthChartWidget;
use App\Modules\Analytics\Filament\Widgets\MoneyPulseWidget;
use App\Modules\Analytics\Filament\Widgets\PlatformPulseWidget;
use App\Modules\Analytics\Filament\Widgets\RevenueChartWidget;
use App\Modules\Analytics\Filament\Widgets\StudentMoneyWidget;
use App\Modules\Analytics\Filament\Widgets\StudentPerformanceWidget;
use App\Modules\Analytics\Filament\Widgets\TopTeachersWidget;
use App\Modules\Analytics\Filament\Widgets\TrustPulseWidget;
use App\Modules\Analytics\Filament\Widgets\ViolationsWidget;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * لوحةُ المؤشّرات — القراءةُ الحيّةُ للمنصّة.
 *
 * ⚠️ **شاشةٌ ثانيةٌ بجانبِ {@see PlatformAnalytics} لا بديلٌ عنها، والفرقُ عَقْدٌ
 * لا ذوق.** تلك الشاشةُ تُصرِّحُ في رأسِها بأنّ «لا شيءَ فيها يحسبُ رقماً من
 * جدولٍ مصدر» — ‏FR-044 يمنعُ المسحَ عندَ كلِّ عرض، و`SC-012` يطلبُ فارقاً
 * صفريّاً بينَها وبينَ التجميعِ الليليّ، وهو معنىً يسقطُ في اللحظةِ التي يُضافُ
 * فيها استعلامٌ حيٌّ إليها. فالقوائمُ الحيّةُ — أعلى الطلابِ دفعاً، المتعثّرون،
 * المدرّسونَ بعددِ الطلاب — تسكنُ هنا، وذاك السجلُّ اليوميُّ المُدقَّقُ يبقى كما
 * هو. واحدةٌ تجيبُ «ما الذي جرى أمسِ ويمكنُ تدقيقُه»، والأخرى «ما الحالُ الآن».
 *
 * ⚠️ **والبابُ على الصفحةِ وعلى كلِّ ويدجتٍ فيها.**
 * {@see PlatformWideWidget}
 * يشرحُ لماذا لا يكفي بابُ الصفحة، ولماذا لا يُسجَّلُ أيٌّ من هذه في
 * `->widgets([])` بمزوّدِ اللوحة: تلك القائمةُ تُصيَّرُ على `/admin` المشتركة،
 * وكلُّ مدرّسٍ يصلُها — فويدجتُ إيراداتٍ هناك يُسلِّمُ كلَّ مدرّسٍ أرقامَ
 * منافسيه.
 *
 * ⚠️ **و`$routePath` لا `$slug` وحدَه**: هذه الصفحةُ ترثُ `Dashboard` لتحصلَ على
 * شبكةِ الويدجتات، و`Dashboard::getRoutePath()` يقرأُ `$routePath` — وقيمتُه
 * الموروثةُ `'/'`، أي أنّ تركَه يخطفُ لوحةَ `/admin` الرئيسيّةَ من مكانِها.
 */
class PlatformInsights extends Dashboard
{
    protected static string $routePath = '/insights';

    protected static ?string $slug = 'insights';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::ANALYTICS_CROSS_TEACHER_VIEW) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'لوحة المؤشّرات';
    }

    public function getTitle(): string
    {
        return 'لوحة المؤشّرات';
    }

    public function getSubheading(): ?string
    {
        return 'أرقامٌ حيّةٌ من الجداول. السجلُّ اليوميُّ المُدقَّق في «تحليلات المنصّة».';
    }

    /** @return array<int, class-string<Widget>> */
    public function getWidgets(): array
    {
        return [
            PlatformPulseWidget::class,
            MoneyPulseWidget::class,
            TrustPulseWidget::class,
            RevenueChartWidget::class,
            GrowthChartWidget::class,
            StudentPerformanceWidget::class,
            StudentMoneyWidget::class,
            TopTeachersWidget::class,
            ViolationsWidget::class,
        ];
    }

    /** @return int|array<string, int|null> */
    public function getColumns(): int|array
    {
        return 1;
    }
}
