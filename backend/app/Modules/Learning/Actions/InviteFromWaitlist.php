<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Listeners\LeaveWaitlistOnEnrolment;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CourseWaitlistEntry;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * دعوةُ أوائلِ الدَّورِ إلى مقاعدَ فُتِحَت (٠٣٤ · FR-029).
 *
 * ⛔ **والدعوةُ تُطالَبُ صفّاً صفّاً بتحديثٍ شرطيّ، لا تُقرَأُ «الأوائلُ N» ثمّ
 * تُكتَب.** موظَّفانِ يفتحانِ مجموعتَينِ في اللحظةِ نفسِها يقرآنِ القائمةَ نفسَها
 * ويدعوانِ **الأشخاصَ أنفسَهم** إلى مقعدَين — فيُدعى اثنانِ إلى أربعةِ مقاعدَ
 * ويبقى اثنانِ في الدَّورِ لا يُدعَيانِ أبداً. وهي القراءةُ-ثمّ-الكتابةُ التي دفعَ
 * ثمنَها هذا المستودعُ خمسَ مرّات (`captured_order_id` · `StructureVersion::claim()`
 * · `media_asset_id` · `broadcast_room_id` · `claimSeat()`). فالشرطُ
 * `invited_at IS NULL` هو الفحصُ والمطالبةُ معاً، والفائزُ هو من غيَّرَ صفّاً.
 *
 * ⚠️ **وعددُ المقاعدِ يُحسَبُ في PHP لا في SQL.** طرحُ عمودَينِ غيرِ مُشارَينِ
 * (`capacity - members_count`) **يرمي ERROR 1690 على MySQL** حينَ يكونُ العددُ
 * الحاليُّ أكبرَ من السعة — وهي حالةٌ ممكنةٌ لأنّ تخفيضَ السعةِ لا يشترطُ
 * أرضيّةً — **ولا يُنتِجُ شيئاً على SQLite**، فلا اختبارٌ محلّيٌّ يراه.
 * و{@see Cohort::seatsLeft()} هو الإملاءُ الواحدُ لهذا الطرح.
 *
 * ⚠️ **ومَن لم يُدعَ لا يُبلَّغُ بشيء** (FR-029). حالُه لم تتغيّرْ، ورسالةٌ تقولُ
 * «فُتِحَت مقاعد» لمن لم ينَلْ واحداً منها هي وعدٌ يُخلَف — والدَّورُ لا يَعِدُ.
 *
 * ⚠️ **والصفُّ لا يُختَم بالدعوة.** `invited_at` يقولُ «دُعيَ» و`closed_at` يقولُ
 * «خرج»، وخروجُه يقعُ عندَ تسجيلِه فعلاً ({@see LeaveWaitlistOnEnrolment}).
 * فمدعوٌّ لم يسجّلْ يبقى في السجلِّ مدعوّاً — وهو ما يُفرِّقُ «لم يأتِ دورُه» عن
 * «جاءَ دورُه فلم يأخذْه».
 */
class InviteFromWaitlist extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly DispatchNotification $notifications,
    ) {}

    /**
     * @return int عددُ من دُعيَ فعلاً
     */
    public function handle(Cohort $cohort, User $actor): int
    {
        $seats = $cohort->seatsLeft();

        if ($seats === 0) {
            return 0;
        }

        $courseId = (int) $cohort->course_id;

        /*
        | مرشَّحونَ لا مدعوّون: كلُّ صفٍّ منهم يُطالَبُ بعدَ ذلكَ على حدة، وقد
        | يخسرُ. والحدُّ مرفوعٌ بواحدٍ لا شيءَ فيه — القائمةُ تُقرَأُ مرّةً،
        | والخاسرُ لا يُعوَّضُ في هذه الجولة: المقعدُ الذي خسرَه ذهبَ إلى مدعوٍّ
        | آخرَ في الجولةِ الموازية، فهو ليسَ شاغراً.
        */
        $candidates = CourseWaitlistEntry::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            ->where('closed_slot', 0)
            ->whereNull('invited_at')
            /*
            | ⚠️ **صفٌّ لا يُسمّي أحداً لا يستهلكُ دعوة.** العمودُ بلا مفتاحٍ
            | أجنبيٍّ على المحرّكَين، فحسابٌ يزولُ يترُكُ صفَّه قائماً — ولولا
            | هذا الشرطُ لادّعى الصفُّ مقعداً ثمّ لم تُرسَلْ عنه رسالةٌ إلى أحد،
            | فيضيعُ المقعدُ على من ينتظرُه فعلاً.
            */
            ->whereHas('student')
            ->orderBy('created_at')
            ->orderBy('id')
            ->when($seats !== null, fn (Builder $query): Builder => $query->limit(max(1, (int) $seats)))
            ->get();

        $invited = 0;
        $now = Carbon::now();

        foreach ($candidates as $entry) {
            // الفحصُ والمطالبةُ في جملةٍ واحدة — انظرْ وصفَ الصنف.
            $claimed = CourseWaitlistEntry::query()
                ->withoutWorkspaceScope()
                ->whereKey($entry->getKey())
                ->whereNull('invited_at')
                ->where('closed_slot', 0)
                ->update(['invited_at' => $now, 'updated_at' => $now]);

            if ($claimed === 0) {
                continue;
            }

            $invited++;
            $this->notify($entry, $cohort);
        }

        if ($invited > 0) {
            // مَن دعا، وكم دُعي. والفاعلُ صريحٌ لا من `Auth`: هذا الفعلُ يُستدعى
            // كذلك من حيثُ لا جلسةَ فيه.
            $this->logActivity('waitlist.invited', $cohort, [
                'workspace_id' => (int) $cohort->workspace_id,
                'invited' => $invited,
                'actor_id' => $actor->getKey(),
            ]);
        }

        return $invited;
    }

    /**
     * ⚠️ الكورسُ يُقرَأُ بتجاوزِ النطاق: الفاعلُ قد يكونُ موظَّفَ منصّةٍ سياقُه
     * مساحةٌ أخرى، فقراءةٌ مُنطَّقةٌ تردُّ `null` وتُرسِلُ رسالةً بلا اسمِ كورس.
     */
    private function notify(CourseWaitlistEntry $entry, Cohort $cohort): void
    {
        // الطالبُ مضمونٌ بـ`whereHas('student')` فوقَ حلقةِ المطالبة.
        $student = $entry->student;

        $course = Course::query()->withoutWorkspaceScope()->find($entry->course_id);

        $this->notifications->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::WaitlistInvited,
            variables: [
                'course_title' => $course instanceof Course ? (string) $course->title : '',
                'cohort_name' => (string) $cohort->name,
            ],
            actionUrl: '/courses/'.($course instanceof Course ? (string) $course->slug : ''),
            subject: $student,
            workspaceId: (int) $entry->workspace_id,
        ));
    }
}
