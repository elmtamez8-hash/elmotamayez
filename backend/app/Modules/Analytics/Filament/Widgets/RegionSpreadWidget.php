<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Actions\ReadPlatformAnalytics;
use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use Filament\Widgets\ChartWidget;

/**
 * توزيعُ الطلابِ بالمنطقة — آخرُ ما بقيَ من شاشةِ «تحليلات المنصّة» بعدَ حذفِها.
 *
 * ⚠️ **يقرأُ {@see ReadPlatformAnalytics} ولا يستعلمُ بنفسِه.** ذلك الإجراءُ يبدأُ
 * من `regions` ويُسنِدُ يساراً، فمنطقةٌ لم يسجّلْ منها أحدٌ تظهرُ بصفرٍ بدلَ أن
 * تختفي — والتجميعُ يكتبُ ما عدَّه فقط، فتقريرٌ مبنيٌّ من صفوفِه وحدَها يُسقِطُ
 * المنطقةَ الفارغةَ بدلَ أن يُجيبَ عنها. وهو أيضاً ما يُبقي هذا الويدجتَ متّفقاً
 * مع ما يُرسِلُه `/api/v1/reports/platform` والتقريرُ المجدوَل: ثلاثةُ قرّاءٍ
 * لإجابةٍ واحدة.
 *
 * ⚠️ **ومن لم يُسألْ عن منطقتِه له خانتُه**: العمودُ فارغٌ في كلِّ حسابٍ سبقَه،
 * وطيُّهم في منطقةٍ اختراعٌ، وحذفُهم يجعلُ مجموعَ الأعمدةِ أقلَّ من عددِ الطلابِ
 * بلا سطرٍ على الشاشةِ يُفسِّرُ الفارق.
 */
class RegionSpreadWidget extends ChartWidget
{
    use PlatformWideWidget;

    protected static ?int $sort = 10;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'توزيع الطلاب بالمنطقة (من التجميع الليليّ)';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        /** @var list<array{slug: string, name: string, students: int}> $regions */
        $regions = app(ReadPlatformAnalytics::class)->handle()['regions'];

        return [
            'labels' => array_map(fn (array $region): string => $region['name'], $regions),
            'datasets' => [[
                'label' => 'الطلاب',
                'data' => array_map(fn (array $region): int => $region['students'], $regions),
                'backgroundColor' => '#0ea5e9',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
