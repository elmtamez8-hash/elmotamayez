<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Learning\Models\Enrollment;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EnrollmentStatsWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $counts = Enrollment::query()
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
