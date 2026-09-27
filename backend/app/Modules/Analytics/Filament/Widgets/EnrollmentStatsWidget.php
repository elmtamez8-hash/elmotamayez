<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Learning\Models\Enrollment;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EnrollmentStatsWidget extends BaseWidget
{
    // ⚠️ بابُ ويدجتاتِ المنصّةِ نفسُه: لا مدرّسَ يدخلُ اللوحة، فلا «أرقامُ مساحتي»
    // يبقى لها قارئٌ غيرُ موظّفٍ يرى مساحتَه مصادفة.
    use PlatformWideWidget;

    /*
    | بعدَ ويدجتاتِ المنصّة.
    |
    | ⛔ **كانَ منطاقاً بالمساحة، ولافتتُه تقولُ «في مساحتك»** — على لوحةٍ لا يصلُها
    | إلّا موظّفُ المنصّة. فمديرُ المنصّةِ الذي يملكُ مساحةً قرأَ أرقامَ مساحتِه
    | وحدَها تحتَ ويدجتاتٍ كلُّها منصّيّة، والرقمُ نفسُه كانَ يعني شيئَينِ بحسبِ
    | `last_workspace_id`. القراءةُ الآنَ منصّيّةٌ كجيرانِها، واللافتةُ تقولُ ذلك.
    */
    protected static ?int $sort = 20;

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return 'التسجيلات — على المنصّة كلّها';
    }

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $counts = Enrollment::query()
            ->withoutWorkspaceScope()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $total = (int) $counts->sum();
        $active = (int) ($counts['active'] ?? 0);
        $completed = (int) ($counts['completed'] ?? 0);

        return [
            Stat::make('إجمالي التسجيلات', (string) $total)
                ->description('كلّ التسجيلات منذ البداية')
                ->descriptionIcon(Heroicon::OutlinedUserGroup)
                ->color('primary'),
            Stat::make('التسجيلات النشِطة', (string) $active)
                ->description('طلابٌ يدرسون الآن')
                ->descriptionIcon(Heroicon::OutlinedAcademicCap)
                ->color('success'),
            Stat::make('الكورسات المكتملة', (string) $completed)
                ->description('تسجيلاتٌ بلغَت ١٠٠٪')
                ->descriptionIcon(Heroicon::OutlinedCheckBadge)
                ->color('info'),
        ];
    }
}
