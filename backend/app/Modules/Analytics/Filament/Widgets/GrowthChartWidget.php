<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Analytics\Models\PlatformMetricDaily;
use App\Modules\Analytics\Support\MetricKey;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * النموّ — من **التجميعِ الليليّ** لا من الجداولِ المصدر.
 *
 * ⚠️ ‏FR-044 يمنعُ مسحَ السجلّاتِ عندَ كلِّ عرض، و`SC-012` يطلبُ فارقاً صفريّاً
 * بينَ الشاشةِ والمصدر — وهو معنىً لا يقومُ إلّا إن قرأَ الاثنانِ الصفَّ نفسَه.
 * فهذا البيانُ يقرأُ `platform_metrics_daily` ولا يعدُّ شيئاً بنفسِه، تماماً كما
 * تفعلُ بقيّةُ ويدجتاتِ المنصّة.
 *
 * ⚠️ **ويومٌ بلا صفٍّ يُترَكُ فراغاً (`null`) لا صفراً.** التجميعُ يكتبُ ما عدَّه؛
 * يومٌ لم يمرَّ عليه الجدولُ ليس يوماً بلا طلاب. وChart.js يقطعُ الخطَّ عندَ
 * `null` بدلَ أن يهبطَ به إلى القاعِ ويُري انهياراً لم يقعْ — وهو الفرقُ بينَ
 * «لا نعلم» و«صفر» الذي يسري على كلِّ لوحةٍ في هذا المستودع.
 */
class GrowthChartWidget extends ChartWidget
{
    use PlatformWideWidget;

    protected static ?int $sort = 5;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'النموّ (من التجميع الليليّ)';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '30';

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
        $days = (int) ($this->filter ?? 30);
        $start = Carbon::today()->subDays($days - 1);

        $series = [MetricKey::StudentsActive, MetricKey::TeachersActive];

        $rows = PlatformMetricDaily::query()
            ->where('workspace_id', 0)
            ->whereIn('metric_key', array_map(fn (MetricKey $key): string => $key->value, $series))
            ->where('date', '>=', $start->format('Y-m-d'))
            ->get();

        $labels = [];

        for ($day = $start->copy(); $day->lte(Carbon::today()); $day->addDay()) {
            $labels[] = $day->format('Y-m-d');
        }

        $palette = ['#0ea5e9', '#10b981'];
        $datasets = [];

        foreach ($series as $index => $key) {
            $byDay = $rows
                ->where('metric_key', $key->value)
                ->mapWithKeys(fn (PlatformMetricDaily $row): array => [
                    Carbon::parse((string) $row->date)->format('Y-m-d') => (int) $row->numerator,
                ]);

            $datasets[] = [
                'label' => $key->label(),
                'data' => array_map(fn (string $day): ?int => $byDay[$day] ?? null, $labels),
                'borderColor' => $palette[$index],
                'backgroundColor' => $palette[$index].'33',
                'spanGaps' => false,
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
