<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Learning\Events\CohortTransferRequestDropped;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * «سقطَ طلبُ انتقالِك، وهذا لماذا» (٠٣٤ · FR-008).
 *
 * ⚠️ **ولا تُرسَلُ لمن سحبَ طلبَه بنفسِه.** `WithdrawTransferRequest` يمرُّ من
 * البابِ نفسِه بفاعلٍ هو الطالب، ورسالةٌ تُخبِرُه بما فعلَه قبلَ ثانيةٍ هي أوّلُ
 * خطوةٍ نحوَ كتمِ القناةِ التي تحملُ تنبيهَ الغياب. الشرطُ هو **الفاعلُ**، لا
 * نصُّ السبب: جملةٌ تُقارَنُ حرفيّاً تنكسرُ عندَ أوّلِ تحريرٍ لها.
 *
 * ⚠️ **وهي غيرُ `CohortTransferRejected`.** الرفضُ قرارٌ اتُّخِذَ في الطلب؛
 * والإسقاطُ أنّ حركةً أخرى جعلَته بلا محلّ — والطالبُ الذي يقرأُ «لم يوافقْ
 * مدرّسك» عن طلبٍ أسقطَته الإدارةُ يذهبُ يسألُ مدرّساً لم يقرأْ طلبَه أصلاً.
 */
class NotifyStudentTransferRequestDropped implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(CohortTransferRequestDropped $event): void
    {
        $request = $event->request;

        if ($event->actorUserId === (int) $request->student_user_id) {
            return;
        }

        $student = $request->student;
        $course = $request->course;

        if ($student === null || $course === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::CohortTransferRequestDropped,
            variables: [
                'course_title' => $course->title,
                // ⚠️ اسمُ المجموعةِ التي صارَ فيها، لا التي كانَ يقصِدُها. الطالبُ
                // يسألُ «فأينَ أنا الآن؟»، والجوابُ هو الرسالةُ نفسُها.
                'cohort_name' => $this->currentCohortName($request->student_user_id, (int) $request->course_id)
                    ?? 'مجموعتك الحاليّة',
            ],
            actionUrl: '/enrollments/'.$course->uuid,
            workspaceId: (int) $request->workspace_id,
        ));
    }

    private function currentCohortName(mixed $studentUserId, int $courseId): ?string
    {
        // ⚠️ متجاوِزةُ النطاقِ كسائرِ ما في هذا المستمِع: عاملٌ بلا سياقِ مساحة.
        $membership = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $studentUserId)
            ->where('course_id', $courseId)
            ->whereNull('closed_at')
            ->first();

        if ($membership === null) {
            return null;
        }

        return Cohort::query()
            ->withoutWorkspaceScope()
            ->whereKey($membership->cohort_id)
            ->value('name');
    }
}
