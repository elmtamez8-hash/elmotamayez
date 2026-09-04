<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Assessments\Models\Attempt;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExamStatsWidget extends BaseWidget
{
    // بعدَ ويدجتاتِ المنصّة: هذان أرقامُ مساحةِ القارئِ نفسِه.
    protected static ?int $sort = 21;

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
            Stat::make('المحاولات المصحَّحة', (string) $total)
                ->description('محاولاتُ اختبارٍ صُحِّحَت')
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('primary'),
            Stat::make('نسبة النجاح', $passRate.'٪')
                ->description('نجح '.$passed.' من '.$total)
                ->descriptionIcon($passRate >= 50 ? Heroicon::OutlinedArrowTrendingUp : Heroicon::OutlinedArrowTrendingDown)
                ->color($passRate >= 50 ? 'success' : 'danger'),
        ];
    }
}
