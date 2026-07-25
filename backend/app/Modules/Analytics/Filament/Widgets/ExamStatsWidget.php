<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Assessments\Models\Attempt;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExamStatsWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $stats = Attempt::query()
            ->where('status', 'graded')
            ->selectRaw('count(*) as total, sum(passed) as passed')
            ->first();

        $total = (int) ($stats->total ?? 0);
        $passed = (int) ($stats->passed ?? 0);
        $passRate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

        return [
            Stat::make('Total Attempts', (string) $total)
                ->description('Graded exam attempts')
                ->color('primary'),
            Stat::make('Pass Rate', $passRate.'%')
                ->description($passed.' passed / '.$total.' total')
                ->color($passRate >= 50 ? 'success' : 'danger'),
        ];
    }
}
