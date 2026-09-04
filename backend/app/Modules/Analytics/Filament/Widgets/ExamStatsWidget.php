<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Filament\Widgets\Concerns\NamesItsScope;
use App\Modules\Assessments\Models\Attempt;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExamStatsWidget extends BaseWidget
{
    use NamesItsScope;

    /*
    | بعدَ ويدجتاتِ المنصّة.
    |
    | ⚠️ ولافتتُه تقولُ **أيَّ نطاقٍ يقرأ**: هذا الويدجتُ منطاقٌ بالمساحة، والنطاقُ
    | خاملٌ لمن لا مساحةَ له — فهو أرقامُ المدرّسِ لمدرّسٍ ومجاميعُ المنصّةِ لمديرٍ،
    | بالرقمِ نفسِه واللافتةِ نفسِها. {@see NamesItsScope}
    */
    protected static ?int $sort = 21;

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return 'الاختبارات — '.$this->scopeLabel();
    }

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
