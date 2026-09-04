<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\LiveSessions\Models\ClassSessionFeedback;
use App\Modules\Marketplace\Models\Complaint;
use App\Modules\Marketplace\Models\Review;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * الرضا والمخالفات.
 *
 * ⛔ **العيّنةُ الفارغةُ ليست صفراً، وهذه هي كلُّ المسألة.** «متوسّطُ الرضا =
 * ٠٫٠» عن منصّةٍ لم يُقيَّمْ فيها أحدٌ بعدُ جملةٌ كاذبةٌ تُقرَأُ حكماً على
 * المدرّسين — وهي عائلةُ `concept_stats.wrong_pct` التي تُخزَّنُ `NULL` ولا
 * تُخزَّنُ صفراً لأنّ «لم يُخطئْ أحد» هو ما يجعلُ مدرّساً يحذفُ سؤالاً جيّداً.
 * فالغيابُ **حالةٌ** تُقالُ بلفظِها: «لا تقييمات بعد».
 *
 * ⚠️ **ورضا وليِّ الأمرِ لا مصدرَ له في الشجرةِ كلِّها**، فلا سطرَ هنا يدّعيه.
 * `reviews` طالبٌ عن مدرّس، و`class_session_feedback` طالبٌ عن حصّة،
 * و`periodic_reviews` مدرّسٌ عن طالب. اشتقاقُه من أيٍّ منها اختراعُ رأيٍ لم
 * يقلْه أحد.
 */
class TrustPulseWidget extends BaseWidget
{
    use PlatformWideWidget;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'الرضا والمخالفات';

    protected int|string|array $columnSpan = 'full';

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $reviews = Review::query()->withoutWorkspaceScope();
        $reviewCount = (clone $reviews)->count();
        $reviewAverage = $reviewCount === 0 ? null : (float) (clone $reviews)->avg('rating');

        $feedback = ClassSessionFeedback::query()->withoutWorkspaceScope();
        $feedbackCount = (clone $feedback)->count();
        $feedbackAverage = $feedbackCount === 0 ? null : (float) (clone $feedback)->avg('rating');

        $complaints = Complaint::query()->withoutWorkspaceScope();
        $confirmed = (clone $complaints)->where('status', 'confirmed')->count();
        $open = (clone $complaints)->where('status', 'pending')->count();

        $moderation = ModerationAction::query()->withoutWorkspaceScope()->count();

        return [
            Stat::make('تقييم المدرّسين', $this->rating($reviewAverage))
                ->description($reviewCount === 0 ? 'لا تقييمات بعد' : 'من '.number_format($reviewCount).' تقييماً')
                ->descriptionIcon(Heroicon::OutlinedStar)
                ->color($reviewAverage === null ? 'gray' : ($reviewAverage >= 4 ? 'success' : 'warning')),
            Stat::make('رضا الحصص', $this->rating($feedbackAverage))
                ->description($feedbackCount === 0 ? 'لا انطباعات بعد' : 'من '.number_format($feedbackCount).' انطباعاً')
                ->descriptionIcon(Heroicon::OutlinedFaceSmile)
                ->color($feedbackAverage === null ? 'gray' : ($feedbackAverage >= 4 ? 'success' : 'warning')),
            Stat::make('الشكاوى المؤكَّدة', number_format($confirmed))
                ->description($open.' شكوى تنتظر البتّ')
                ->descriptionIcon(Heroicon::OutlinedFlag)
                ->color($confirmed > 0 ? 'danger' : 'gray'),
            Stat::make('إجراءات الإشراف', number_format($moderation))
                ->description('حذفُ رسالةٍ أو منعُ كتابةٍ أو حظر')
                ->descriptionIcon(Heroicon::OutlinedShieldExclamation)
                ->color($moderation > 0 ? 'warning' : 'gray'),
        ];
    }

    private function rating(?float $average): string
    {
        // ⚠️ شرطةٌ لا صفر: انظرْ رأسَ الصنف.
        return $average === null ? '—' : number_format($average, 2).' / 5';
    }
}
