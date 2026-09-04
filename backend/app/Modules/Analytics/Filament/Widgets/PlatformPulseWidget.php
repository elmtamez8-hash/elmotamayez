<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Models\User;
use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * الأعداد — الطلابُ والمدرّسونَ والكورساتُ والحصص.
 *
 * ⚠️ كلُّ قراءةٍ هنا تُعلِنُ `withoutWorkspaceScope()`. `WorkspaceContext::id()`
 * يرتدُّ إلى `users.last_workspace_id` **حتّى لمديرِ المنصّة**، فتقريرٌ منطاقٌ
 * يعرضُ أرقامَ مدرّسٍ واحدٍ على أنّها أرقامُ المنصّة — ويمرُّ خضراءَ في تجهيزةٍ
 * بمساحةٍ واحدة. هذا بعينُه ما شُحِنَ في سلسلةِ التدقيقِ وأجابَ «لم يُشترَ شيء»
 * برمزِ ٢٠٠.
 *
 * ⚠️ و«الطلاب» عددُ الحساباتِ بدورِ طالب، لا عددُ من يدرسُ الآن — والثاني
 * مكتوبٌ في صفِّه. `platform_role` فارغٌ في الحساباتِ التي سبقَت العمود، فالوصفُ
 * يقولُ ما يعدُّه الرقمُ بدلَ أن يتركَ القارئَ يفترض.
 */
class PlatformPulseWidget extends BaseWidget
{
    use PlatformWideWidget;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'نبض المنصّة';

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $students = User::query()->where('platform_role', 'student')->count();
        $learning = Enrollment::query()->withoutWorkspaceScope()->where('status', 'active')
            ->distinct()->count('student_user_id');

        $teachers = TeacherProfile::query()->withoutWorkspaceScope()->count();
        $approved = TeacherProfile::query()->withoutWorkspaceScope()
            ->where('approval_status', TeacherProfile::STATUS_APPROVED)->count();

        $courses = Course::query()->withoutWorkspaceScope()->count();
        $published = Course::query()->withoutWorkspaceScope()->where('status', 'published')->count();

        $delivered = ClassSession::query()->withoutWorkspaceScope()->where('status', 'completed')->count();
        $upcoming = ClassSession::query()->withoutWorkspaceScope()->where('status', 'scheduled')
            ->where('starts_at', '>=', now())->count();

        return [
            Stat::make('الطلاب', number_format($students))
                ->description($learning.' منهم يدرسون الآن')
                ->descriptionIcon(Heroicon::OutlinedUserGroup)
                ->color('primary'),
            Stat::make('المدرّسون', number_format($teachers))
                ->description($approved.' معتمَدون')
                ->descriptionIcon(Heroicon::OutlinedAcademicCap)
                ->color('success'),
            Stat::make('الكورسات', number_format($courses))
                ->description($published.' منشورة')
                ->descriptionIcon(Heroicon::OutlinedBookOpen)
                ->color('info'),
            Stat::make('الحصص المُسلَّمة', number_format($delivered))
                ->description($upcoming.' حصّة قادمة')
                ->descriptionIcon(Heroicon::OutlinedVideoCamera)
                ->color('warning'),
        ];
    }
}
