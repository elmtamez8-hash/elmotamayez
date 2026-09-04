<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Analytics\Support\Money;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\PaymentTransaction;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * المال — المحصَّلُ وحصّةُ المنصّةِ والمتأخّرات.
 *
 * ⛔ **«الأرباح» هنا حصّةُ المنصّةِ المُثبَتةُ لحظةَ الشراء**
 * (`operating_fee_minor + gateway_fee_minor`)، ولا تُشتقُّ أبداً بطرحِ أجورِ
 * المدرّسينَ من الإيرادات. الطرحُ يصلُ الفوترةَ بالتسوية، و`ContextIsolationTest`
 * يُسقِطُ البناءَ على ذلك عمداً: هما سياقانِ بلا مفتاحٍ بينَهما، وغيابُ المفتاحِ
 * جزءٌ من التصميم. أجورُ المدرّسينَ رقمٌ ثانٍ من دفترِ التسويةِ وحدَه، لا طرفٌ في
 * طرح.
 *
 * ⛔ **وكلُّ مجموعٍ بالعملة.** الإنتاجُ يحملُ `USD` و`QAR` معاً، فمجموعٌ عبرَهما
 * رقمٌ لا معنى له. {@see Money::line()}
 *
 * ⚠️ والمتأخّراتُ **بالحصصِ لا بالمال**: الرصيدُ السالبُ حصصٌ سُلِّمَت ولم
 * تُدفَع، وتحويلُها إلى مبلغٍ يحتاجُ سعرَ كلِّ مدرّسٍ — وهو ما لا يُجمَعُ عبرَ
 * المنصّة.
 */
class MoneyPulseWidget extends BaseWidget
{
    use PlatformWideWidget;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'المال';

    protected int|string|array $columnSpan = 'full';

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $collected = PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('status', 'captured')
            ->groupBy('currency')
            // ⚠️ `getAttribute` لا `->total`: عمودُ `selectRaw` ليس سِمةً مُعلَنةً
            // على النموذج. و`pluck` على تعبيرٍ خامٍّ لا يصلحُ بديلاً — يشتقُّ اسمَ
            // الخاصّيّةِ من نصِّ العمودِ فيقرأُ اسماً لا وجودَ له في الناتج.
            ->select('currency', DB::raw('SUM(amount_minor) as total'))
            ->get()
            ->map(fn ($row): array => [
                'currency' => (string) $row->getAttribute('currency'),
                'total' => (int) $row->getAttribute('total'),
            ])
            ->values();

        $take = CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->groupBy('currency')
            ->select('currency', DB::raw('SUM(operating_fee_minor + gateway_fee_minor) as total'))
            ->get()
            ->map(fn ($row): array => [
                'currency' => (string) $row->getAttribute('currency'),
                'total' => (int) $row->getAttribute('total'),
            ])
            ->values();

        // ⚠️ CAST إلى SIGNED: الأعمدةُ غيرُ مُوقَّعةٍ على MySQL، وجمعُ سالبٍ
        // يرفعُ ERROR 1690 لا يراه SQLite محليّاً أبداً.
        $overdue = (int) CreditBalance::query()
            ->withoutWorkspaceScope()
            ->where('remaining_credits', '<', 0)
            ->sum(DB::raw('CAST(remaining_credits AS SIGNED)'));

        $defaulters = CreditBalance::query()->withoutWorkspaceScope()
            ->where('remaining_credits', '<', 0)->distinct()->count('student_user_id');

        return [
            Stat::make('المحصَّل', Money::line($collected))
                ->description('حركات دفع مُحصَّلة، مجموعةً بالعملة')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success'),
            Stat::make('حصّة المنصّة', Money::line($take, '٠٫٠٠'))
                ->description('رسوم التشغيل والبوابة، مُثبَتةً لحظة الشراء')
                ->descriptionIcon(Heroicon::OutlinedChartBar)
                ->color('primary'),
            Stat::make('المتأخّرات', number_format(abs($overdue)).' حصّة')
                ->description($defaulters.' طالباً برصيد سالب')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($overdue < 0 ? 'danger' : 'gray'),
        ];
    }
}
