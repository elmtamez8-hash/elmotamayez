<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Payments\Models\PaymentTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * المحصَّلُ يوماً بيوم — **بيانٌ لكلِّ عملة**.
 *
 * ⛔ لا خطَّ واحدَ يجمعُ العملات: الإنتاجُ يحملُ `USD` و`QAR` معاً، وخطٌّ يضمُّهما
 * منحنى لا يصفُ شيئاً. {@see MoneyPulseWidget}
 *
 * ⚠️ **والأيّامُ الصامتةُ تُرسَمُ أصفاراً لا تُحذَف.** استعلامٌ مجموعٌ لا يُعيدُ
 * صفّاً ليومٍ بلا دفع، فبيانٌ مبنيٌّ من صفوفِه وحدَها يضغطُ أسبوعاً هادئاً إلى
 * نقطتَين متجاورتَين ويُري ارتفاعاً لم يحدث. المحورُ يُبنى من التقويمِ ثمّ
 * تُسقَطُ عليه القيم.
 *
 * ⚠️ والترشيحُ بـ`>= بداية` لا بـ`DATE(col) >=`: الأوّلُ يستعملُ الفهرس،
 * والثاني يلفُّ العمودَ فيرميه — درسُ `FreezePeriod::covering()` نفسُه. التجميعُ
 * بـ`DATE()` لا مفرَّ منه في سلسلةٍ يوميّة، والحدُّ الأدنى هو ما يُبقيه رخيصاً.
 */
class RevenueChartWidget extends ChartWidget
{
    use PlatformWideWidget;

    protected static ?int $sort = 4;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'المحصَّل يوماً بيوم';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '90';

    /**
     * ⚠️ مفاتيحُ رقميّةٌ نصّاً تصيرُ `int` في مصفوفةِ PHP — والنوعُ يقولُ ذلك
     * بدلَ أن يدّعيَ `array<string, string>` ويكذب.
     *
     * @return array<int, string>
     */
    protected function getFilters(): array
    {
        return ['30' => 'آخر ٣٠ يوماً', '90' => 'آخر ٩٠ يوماً', '365' => 'آخر سنة'];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 90);
        $start = Carbon::today()->subDays($days - 1);

        $rows = PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('status', 'captured')
            ->where('settled_at', '>=', $start)
            ->groupBy('day', 'currency')
            ->select(
                DB::raw('DATE(settled_at) as day'),
                'currency',
                DB::raw('SUM(amount_minor) as total'),
            )
            ->get();

        /** @var array<string, array<string, float>> $byCurrency */
        $byCurrency = [];

        foreach ($rows as $row) {
            // ⚠️ `getAttribute()` لا `->day`: أعمدةُ `selectRaw` ليست سِماتٍ
            // مُعلَنةً على النموذج.
            $currency = (string) $row->getAttribute('currency');
            $byCurrency[$currency][(string) $row->getAttribute('day')] = (float) $row->getAttribute('total') / 100;
        }

        $labels = [];

        for ($day = $start->copy(); $day->lte(Carbon::today()); $day->addDay()) {
            $labels[] = $day->format('Y-m-d');
        }

        $palette = ['#0ea5e9', '#f59e0b', '#10b981', '#a855f7', '#ef4444'];
        $datasets = [];

        foreach (array_keys($byCurrency) as $index => $currency) {
            $colour = $palette[$index % count($palette)];

            $datasets[] = [
                'label' => $currency,
                'data' => array_map(fn (string $day): float => $byCurrency[$currency][$day] ?? 0.0, $labels),
                'borderColor' => $colour,
                'backgroundColor' => $colour.'33',
                'fill' => true,
                'tension' => 0.3,
            ];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true]]];
    }
}
