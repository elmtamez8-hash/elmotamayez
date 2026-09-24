<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Events\CohortMembershipOpened;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Contracts\CohortScheduleDirectory;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * «أُسنِدتَ إلى مجموعة، وهذه مواعيدُها» (٠٣٤ · FR-006).
 *
 * ⚠️ **مستمِعٌ جديدٌ تماماً، لا «كما يُبلَّغُ اليوم».** قِيسَ:
 * `CohortMembershipWriter::open()` يُطلِقُ `CohortMembershipOpened` ومستمِعُه
 * الوحيدُ في الشجرةِ يُحرِّرُ المقاعدَ ويحجزُها — لا إشعارَ في أيِّ موضع. وبابُ
 * المدرّسِ «أضِفْ عضواً» كانَ يضعُ الطالبَ في مجموعةٍ ولا يُبلِّغُه، فلا يعلمُ
 * حتّى يفتحَ جدولَه من تلقاءِ نفسِه.
 *
 * ⚠️ **وعلى `ASSIGNED` وحدَها.** الحدثُ يقعُ على أربعةِ مسارات: الطالبُ الذي
 * انضمَّ بنفسِه يرى النتيجةَ على الشاشةِ التي ضغطَ فيها، وانتقالٌ وُوفِقَ عليه
 * **له إشعارُه** (`CohortTransferApproved`) بجملةٍ أدقَّ من هذه — فإرسالُ هذه
 * معه رسالتانِ عن حركةٍ واحدة، وهو أوّلُ ما يجعلُ العائلةَ تكتمُ الرقم.
 *
 * ⚠️ **ونقلُ المدرّسِ اليدويُّ (`TRANSFERRED`) يبقى بلا إشعارٍ عمداً، وهي فجوةٌ
 * مكتوبةٌ لا منسيّة**: `MoveMember` و`DecideTransferRequest` يكتبانِ الثابتَ
 * نفسَه، فلا يُفرَّقُ بينَهما من هنا — وتمييزُهما قرارُ مواصفةٍ لم تطلبْه ٠٣٤.
 *
 * ⚠️ **والمواعيدُ في المتن، و«لم تُعلَنْ بعد» تُقالُ صراحة** (سابقةُ ٠٢٧ ·
 * FR-029أ): سطرٌ محذوفٌ يُقرَأُ عُطلاً، فيُحدِّثُ الطالبُ الصفحةَ ويسألُ إن كانَ
 * إسنادُه وقعَ أصلاً.
 *
 * ⚠️ **وكلُّ قراءةٍ هنا تتجاوزُ النطاق.** هذا يعملُ على عاملٍ بلا سياقِ مساحةٍ
 * إطلاقاً، و`null` من علاقةٍ مُنطَّقةٍ **بعدَ** أن كُتِبَت العضويّةُ إشعارٌ لا
 * يصلُ أحداً بلا سطرِ خطأ — وهي الطبقةُ الصامتةُ من طبقاتِ ٠٢٤.
 */
class NotifyStudentCohortAssigned implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly CohortScheduleDirectory $schedules,
    ) {}

    public function handle(CohortMembershipOpened $event): void
    {
        if ($event->membershipEvent !== CohortMembershipEvent::ASSIGNED) {
            return;
        }

        $student = User::query()->find($event->studentUserId);
        $course = Course::query()->withoutWorkspaceScope()->find($event->courseId);
        $cohort = Cohort::query()->withoutWorkspaceScope()->find($event->toCohortId);

        if (! $student instanceof User || ! $course instanceof Course || ! $cohort instanceof Cohort) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::CohortAssigned,
            variables: [
                'cohort_name' => $cohort->name,
                'course_title' => $course->title,
                'schedule' => $this->scheduleLine((int) $cohort->getKey()),
            ],
            // صفحةُ الكورس: تحملُ لوحةَ المجموعةِ ومواعيدَها، فيهبطُ الطالبُ على
            // الجواب لا على قائمةٍ يبحثُ فيها.
            actionUrl: '/enrollments/'.$course->uuid,
            // ⚠️ The subject is what fans the message out to guardians
            // (`RecipientResolver`): without it `targetsGuardians()` reaches
            // nobody while the type still carries the paid channel.
            subject: $student,
            workspaceId: $event->workspaceId,
        ));
    }

    /**
     * الإيقاعُ الأسبوعيُّ وأقربُ حصّة — وكلاهما، لا أحدُهما.
     *
     * الإيقاعُ («السبت ٥م») هو ما تُنظَّمُ عليه أسابيعُ العائلةِ ولا يقولُ **أيَّ**
     * سبت؛ والتاريخُ يقولُ أيَّ يومٍ ويُخفي الإيقاع. واحدٌ بلا الآخرِ رسالةٌ
     * تُنتِجُ السؤالَ الذي أُرسِلَت لتُجيبَه.
     */
    private function scheduleLine(int $cohortId): string
    {
        $slots = $this->schedules->schedulePreviewFor([$cohortId])[$cohortId] ?? [];

        $line = $slots === []
            ? 'لم تُعلَن مواعيدها الأسبوعيّة بعد؛ ستصلك رسالة فور إعلانها.'
            : 'مواعيدها: '.implode(' · ', $slots).'.';

        $next = $this->schedules->nextSessionFor($cohortId);

        return $next === null
            ? $line
            : $line.' أقرب حصّة: '.$next['label'].'.';
    }
}
