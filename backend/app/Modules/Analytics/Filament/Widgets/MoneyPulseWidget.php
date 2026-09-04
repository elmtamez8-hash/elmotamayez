<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Analytics\Support\Money;
use App\Modules\Payments\Enums\Currency;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\PaymentTransaction;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * المال — عدّادٌ **لكلِّ عملةٍ على حدة**.
 *
 * ⛔ **عدّادٌ واحدٌ يضمُّ عملتَينِ لا يُقرَأ، وهذه ليست مسألةَ ذوق.** شُحِنَ أوّلَ
 * مرّةٍ سطراً واحداً — «‏99.98 USD · 480.00 QAR» — فأبلغَ صاحبُ المنتَجِ أنّه
 * «غيرُ مفهوم». والسببُ في الاتّجاهِ لا في الأرقام: أرقامٌ لاتينيّةٌ ورموزٌ
 * لاتينيّةٌ وفاصلٌ داخلَ نصٍّ عربيٍّ تُعادُ ترتيبُها بصريّاً بقواعدِ bidi، فيقرأُ
 * صاحبُ الشاشةِ ترتيباً غيرَ الذي كُتِب. وفوقَ ذلك: رقمانِ لعملتَينِ في خانةٍ
 * واحدةٍ يُقرآنِ مجموعاً واحداً، وهو بالضبطِ الوهمُ الذي مُنِعَ الجمعُ لأجلِه.
 *
 * فلكلِّ عملةٍ عدّادُها، بعنوانٍ عربيٍّ يسمّيها، ورمزٍ عربيٍّ قصيرٍ يُبقي السطرَ في
 * اتّجاهٍ واحد — **وحصّةُ المنصّةِ من تلك العملةِ تحتَه**، لا في عدّادٍ ثانٍ
 * يُتركُ للقارئِ أن يُطابِقَه بنفسِه.
 *
 * ⛔ **و«حصّةُ المنصّة» مُثبَتةٌ لحظةَ الشراء** (`operating_fee_minor +
 * gateway_fee_minor`)، ولا تُشتقُّ أبداً بطرحِ أجورِ المدرّسينَ من الإيرادات:
 * الطرحُ يصلُ الفوترةَ بالتسوية، و`ContextIsolationTest` يُسقِطُ البناءَ على ذلك
 * عمداً — هما سياقانِ بلا مفتاحٍ بينَهما، وغيابُ المفتاحِ جزءٌ من التصميم.
 *
 * ⚠️ **وعملةُ المنصّةِ أوّلاً** (`config('billing.currency')`): الترتيبُ الأبجديُّ
 * يضعُ `USD` قبلَ `QAR`، فيقعُ أوّلُ رقمٍ تراه العينُ على العملةِ الأقلِّ شأناً.
 *
 * ⚠️ **والمتأخّراتُ بالحصصِ لا بالمال**: الرصيدُ السالبُ حصصٌ سُلِّمَت ولم تُدفَع،
 * وتحويلُها إلى مبلغٍ يحتاجُ سعرَ كلِّ مدرّس — وهو ما لا يُجمَعُ عبرَ المنصّة.
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
        $collected = $this->byCurrency(
            PaymentTransaction::query()
                ->withoutWorkspaceScope()
                ->where('status', 'captured'),
            DB::raw('SUM(amount_minor) as total'),
        );

        $take = $this->byCurrency(
            CreditPurchase::query()->withoutWorkspaceScope(),
            DB::raw('SUM(operating_fee_minor + gateway_fee_minor) as total'),
        );

        $stats = [];

        foreach ($this->currencies($collected, $take) as $currency) {
            $label = Currency::tryFrom($currency)?->label() ?? $currency;

            $stats[] = Stat::make('المحصَّل — '.$label, Money::format($collected[$currency] ?? 0, $currency))
                // حصّةُ المنصّةِ تحتَ إيرادِها لا في عدّادٍ منفصل: الرقمانِ يُقرآنِ
                // معاً أو لا يُقرآنِ أصلاً.
                ->description('حصّة المنصّة منها: '.Money::format($take[$currency] ?? 0, $currency))
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success');
        }

        // ⚠️ CAST إلى SIGNED: الأعمدةُ غيرُ مُوقَّعةٍ على MySQL، وجمعُ سالبٍ عليها
        // يرفعُ ERROR 1690 — ولا حسابَ غيرَ مُوقَّعٍ في SQLite ليُظهِرَه محليّاً.
        $overdue = (int) CreditBalance::query()
            ->withoutWorkspaceScope()
            ->where('remaining_credits', '<', 0)
            ->sum(DB::raw('CAST(remaining_credits AS SIGNED)'));

        $defaulters = CreditBalance::query()->withoutWorkspaceScope()
            ->where('remaining_credits', '<', 0)->distinct()->count('student_user_id');

        $stats[] = Stat::make('المتأخّرات', number_format(abs($overdue)).' حصّة')
            ->description($defaulters.' طالباً برصيد سالب')
            ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
            ->color($overdue < 0 ? 'danger' : 'gray');

        return $stats;
    }

    /**
     * مجموعٌ لكلِّ عملة.
     *
     * ⚠️ `select ... as total` ثمّ `getAttribute`، ولا `pluck` على تعبيرٍ خام:
     * `pluck` يشتقُّ اسمَ الخاصّيّةِ من نصِّ العمود، فيقرأُ من
     * `SUM(payment_transactions.amount_minor)` الاسمَ `amount_minor` — وهو ليس
     * اسمَ أيِّ عمودٍ في الناتج، فيرمي «Undefined property» داخلَ التصيير. قِيسَ.
     *
     * ⚠️ والتعبيرُ يصلُ **مبنيّاً** لا نصّاً يُركَّبُ هنا: `DB::raw()` على سلسلةٍ
     * مُركَّبةٍ بابٌ لحقنِ SQL ولو لم يكنْ مفتوحاً اليوم، وPHPStan يرفضُ غيرَ
     * الحرفيِّ لهذا السبب.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private function byCurrency($query, Expression $total): array
    {
        $totals = [];

        foreach ($query->groupBy('currency')->select('currency', $total)->get() as $row) {
            $totals[(string) $row->getAttribute('currency')] = (int) $row->getAttribute('total');
        }

        return $totals;
    }

    /**
     * العملاتُ المعروضة — عملةُ المنصّةِ أوّلاً، ثمّ ما وردَ فعلاً.
     *
     * ⚠️ وعملةُ المنصّةِ تظهرُ **ولو بصفر**: لوحةٌ خاليةٌ من عدّادِ المال يوماً بلا
     * تحصيلٍ تُقرَأُ شاشةً معطّلةً لا يوماً هادئاً.
     *
     * @param  array<string, int>  $collected
     * @param  array<string, int>  $take
     * @return list<string>
     */
    private function currencies(array $collected, array $take): array
    {
        $home = (string) config('billing.currency', 'QAR');

        $rest = array_diff(array_unique([...array_keys($collected), ...array_keys($take)]), [$home]);

        $rest = array_values($rest);
        sort($rest);

        return [$home, ...$rest];
    }
}
