<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Learning\Models\Enrollment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EnrollmentStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = null;

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
            Stat::make('Total Enrollments', (string) $total)
                ->description('All-time enrollments')
                ->color('primary'),
            Stat::make('Active Enrollments', (string) $active)
                ->description('Currently learning')
                ->color('success'),
            Stat::make('Completed Courses', (string) $completed)
                ->description('Course completions')
                ->color('info'),
        ];
    }
}
