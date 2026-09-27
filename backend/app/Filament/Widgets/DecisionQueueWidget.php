<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Contracts\AwaitsDecision;
use App\Filament\Resources\OrderResource;
use App\Modules\Courses\Filament\Pages\ReviewPromoVideos;
use App\Modules\Gamification\Filament\Resources\RedemptionResource;
use App\Modules\Marketplace\Filament\Resources\TeacherApplicationResource;
use App\Modules\Payments\Enums\OrderStatus;
use App\Modules\Payments\Filament\Pages\GrantCreditSubscription;
use App\Modules\Payments\Filament\Pages\ReviewPlanChanges;
use App\Modules\Settlement\Filament\Pages\ReviewRateRequests;
use Closure;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * «ينتظر قرارك» — أوّلُ ما يُرى على اللوحة: بطاقةٌ لكلِّ طابورٍ يصلُه القارئ.
 *
 * ⚠️ **الرقمُ هنا هو رقمُ القائمةِ نفسُه، من الدالّةِ نفسِها.** كلُّ بطاقةٍ تقرأُ
 * `pendingCount()` على شاشتِها ({@see AwaitsDecision})، وهي ما يقرؤه عدّادُ
 * القائمة — فلا يقفُ الموظّفُ أمامَ رقمَينِ مختلفَينِ للطابورِ الواحد.
 *
 * ⚠️ **ولا مجموع.** طلبُ اشتراكٍ معلَّقٌ طلبٌ على شاشتَين («اعتماد المدفوعات» و«طلبات
 * الاشتراك») فيُعَدُّ في البطاقتَين؛ جمعُهما يكذبُ بضعفِه.
 *
 * ⚠️ **وبابُ البطاقةِ بابُ شاشتِها** (`decisionQueueVisible()`): موظّفُ الماليّةِ
 * لا يرى طابورَ الفيديوهات، ومسؤولُ الامتثالِ لا يرى المدفوعات. وليس هذا الويدجتُ
 * من ويدجتاتِ المنصّةِ ذاتِ الباب `analytics.cross_teacher.view` — تلك لا يحملُها
 * موظّفٌ ماليٌّ، فكانَ سيُحرَمُ طابورَه هو.
 */
class DecisionQueueWidget extends BaseWidget
{
    /** قبلَ ويدجتِ الحساب (−٣) وكلِّ ما بعدَه: هذا ما يُفتَحُ لأجلِه. */
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return 'ينتظر قرارك';
    }

    public static function canView(): bool
    {
        foreach (self::queues() as $queue) {
            if ($queue['class']::decisionQueueVisible()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $stats = [];
        $anything = false;

        foreach (self::queues() as $queue) {
            $class = $queue['class'];

            if (! $class::decisionQueueVisible()) {
                continue;
            }

            $count = $class::pendingCount();
            $anything = $anything || $count > 0;

            $stats[] = Stat::make($queue['label'], (string) $count)
                ->description($count === 0 ? 'لا شيء هنا' : 'افتح الطابور')
                ->descriptionIcon($count === 0 ? Heroicon::OutlinedCheck : Heroicon::OutlinedArrowLeft)
                ->color($count === 0 ? 'gray' : 'warning')
                ->url(($queue['url'])());
        }

        // مبالغُ يجبُ ردُّها: بطاقةٌ حمراءُ حين توجدُ فقط — إنذارٌ لا طابورٌ دائم.
        if (OrderResource::decisionQueueVisible() && ($refunds = OrderResource::refundDueCount()) > 0) {
            $anything = true;

            $stats[] = Stat::make('مبالغ يجب ردّها', (string) $refunds)
                ->description('طلبات دُفعت وتستحقّ الاسترداد')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->url(OrderResource::getUrl('index', [
                    'filters' => ['status' => ['value' => OrderStatus::RefundDue->value]],
                ]));
        }

        if (! $anything) {
            return [
                Stat::make('لا شيء ينتظرك', '—')
                    ->description('كلّ الطوابير فارغة الآن')
                    ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                    ->color('success'),
            ];
        }

        return $stats;
    }

    /**
     * ترتيبُ مجموعةِ «ينتظر قرارك» في القائمةِ نفسُه.
     *
     * @return list<array{class: class-string<AwaitsDecision>, label: string, url: Closure(): string}>
     */
    private static function queues(): array
    {
        return [
            ['class' => OrderResource::class, 'label' => OrderResource::getNavigationLabel(), 'url' => fn (): string => OrderResource::getUrl('index')],
            ['class' => GrantCreditSubscription::class, 'label' => 'طلبات الاشتراك', 'url' => fn (): string => GrantCreditSubscription::getUrl()],
            ['class' => TeacherApplicationResource::class, 'label' => TeacherApplicationResource::getNavigationLabel(), 'url' => fn (): string => TeacherApplicationResource::getUrl('index')],
            ['class' => ReviewRateRequests::class, 'label' => ReviewRateRequests::getNavigationLabel(), 'url' => fn (): string => ReviewRateRequests::getUrl()],
            ['class' => ReviewPlanChanges::class, 'label' => ReviewPlanChanges::getNavigationLabel(), 'url' => fn (): string => ReviewPlanChanges::getUrl()],
            ['class' => ReviewPromoVideos::class, 'label' => ReviewPromoVideos::getNavigationLabel(), 'url' => fn (): string => ReviewPromoVideos::getUrl()],
            ['class' => RedemptionResource::class, 'label' => RedemptionResource::getNavigationLabel(), 'url' => fn (): string => RedemptionResource::getUrl('index')],
        ];
    }
}
