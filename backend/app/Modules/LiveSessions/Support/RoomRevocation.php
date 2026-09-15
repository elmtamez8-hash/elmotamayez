<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Actions\IssueJoinTicket;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Http\Controllers\BroadcastController;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Tenancy\Support\Permissions;

/**
 * «هل ما زالَ من حقِّ هذا الشخصِ أن يكونَ في الغرفةِ **الآن**؟»
 *
 * ⛔ **وهذا سؤالٌ غيرُ «هل يُسمَحُ له بالدخول»، والخلطُ بينَهما كلّفَ النبضةَ
 * خمسةَ عشرَ استعلاماً بدلَ خمسة.**
 *
 * المزوّدُ لا يملكُ سحبَ تذكرة، ويُعيدُ إنشاءَ غرفةٍ محذوفةٍ عندَ أوّلِ دخول —
 * فأداتُنا الوحيدةُ لإخراجِ أحدٍ هي إعادةُ السؤالِ في كلِّ نبضةٍ والرفض. وذلكَ
 * السؤالُ **لازمٌ ولا يُحذَف** (`TicketAfterCloseTest` يكتبُه). لكنّ
 * {@see BroadcastController::presence()} كانَ يُعيدُ سلسلةَ **الباب** كاملةً:
 * التسجيلَ والإجازةَ والحجبَ وقاعدةَ الفتحِ — أسئلةً إجابتُها لا تتغيّرُ والطالبُ
 * جالسٌ في الحصّة.
 *
 * فالفرقُ هو ما يُقسَمُ عليه:
 *
 *  · **ما يتغيّرُ أثناءَ الحصّةِ ويجبُ أن يُخرِج** — هنا: انتهاءُ النافذة، وإغلاقُ
 *    الغرفة، وإلغاءُ الحصّةِ أو انتهاؤها، وتحريرُ المقعد، وإخراجُ المدرّسِ له.
 *  · **ما حُسِمَ على الباب** — التسجيلُ والإجازةُ والمالُ والواجب. يبقى في
 *    {@see IssueJoinTicket}.
 *
 * ⚠️ **وهذا تغييرُ استحقاقٍ مقصود، لا تحسينُ أداء.** قبلَه: طالبةٌ اضطربَ
 * رصيدُها في منتصفِ الشرحِ تُرمى خارجَ حصّةٍ **دفعَت ثمنَها** عندَ النبضةِ
 * التالية. وذلكَ لم يكنْ تصميماً — كانَ أثراً جانبيّاً لإعادةِ استعمالِ دالّةِ
 * البابِ بحالِها. فمن يفقدُ استحقاقَه الآنَ يُكمِلُ حصّتَه ويُمنَعُ من التي
 * بعدَها.
 *
 * ⛔ **وصنفٌ واحدٌ يملكُ الأسئلةَ، يسألُه البابانِ معاً.** لو كتبَ كلُّ بابٍ
 * نسختَه لصارَ «بابانِ يختلفان» — العطلُ الذي دفعَه هذا المستودعُ في
 * `BookingEligibility` وفي `ListLeaderboardScopes` وفي تسجيلِ ٠١٨ المدفوع.
 *
 * ⚠️ **والتكلفةُ عمودانِ محمّلانِ وصفّان**: النافذةُ والحالةُ تُقرآنِ من الصفِّ
 * الذي رُبِطَ به المسارُ أصلاً (صفرُ استعلامات)، والمقعدُ والإخراجُ استعلامٌ
 * لكلٍّ — وكلاهما **يتغيّرُ** أثناءَ الحصّةِ فلا يجوزُ حفظُهما.
 */
final class RoomRevocation
{
    /**
     * المضيفُ: الصلاحيّةُ ومساحةُ العملِ نفسُها — بصفرِ استعلامٍ بعدَ تحميةِ
     * ذاكرةِ الصلاحيّات.
     *
     * ⚠️ **هنا لا في البابَين.** الصيغةُ كانت مكتوبةً داخلَ `roleFor()`، ونسخةٌ
     * ثانيةٌ منها في النبضةِ تعني «بابانِ يختلفان» عندَ أوّلِ تعديلٍ يمسُّها.
     */
    public function isHost(ClassSession $session, User $user): bool
    {
        return $user->can(Permissions::SESSIONS_HOST)
            && (int) $user->last_workspace_id === (int) $session->workspace_id;
    }

    /**
     * المضيفُ لا يُسأَلُ عن مقعدٍ ولا عن إخراج: له صفُّ
     * حضورٍ خاصٌّ به يُحكَمُ منه التسليم، وفحصُ الإخراجِ فوقَه يجعلُ مضيفاً
     * يُقفِلُ على مضيفٍ آخرَ حصّتَه بزرٍّ مقصودٍ للطلاب.
     */
    public function stillAdmitted(ClassSession $session, User $user): bool
    {
        /*
        | ⚠️ **الساعةُ والحالةُ أوّلاً، وكلتاهما بصفرِ استعلام.**
        |
        | `joinWindowCovers()` يقرأُ `room_closed_at` كذلك — وهو ما يُغلِقُ الغرفةَ
        | على من يحملُ تذكرةً حيّة. والحالةُ النهائيّةُ سؤالٌ ثانٍ لأنّ
        | `CancelClassSession` **لا يختمُ `room_closed_at`**، فحصّةٌ ألغاها
        | المدرّسُ تمرُّ من النافذةِ مروراً تامّاً.
        |
        | ⛔ **وهذا السطرُ بعينِه هو ما أسقطَه الاختبارُ** حينَ كُتِبَ هذا التقسيمُ
        | أوّلَ مرّة: بدونَه ردَّتِ النبضةُ ٢٠٠ على حصّةٍ ملغاة، لأنّ الرفضَ كانَ
        | يأتي بالصدفةِ من دالّةِ فتحِ الغرفةِ في مسارِ المضيف.
        */
        if (! $session->joinWindowCovers(now()) || $session->status->isTerminal()) {
            return false;
        }

        if ($this->isHost($session, $user)) {
            return true;
        }

        // مقعدٌ حُرِّرَ — بإلغاءِ الطالبِ أو بكنسِ المقاعدِ غيرِ المستحقّة — يُخرِج.
        $holdsSeat = $session->bookings()
            ->where('student_user_id', $user->getKey())
            ->where('status', BookingStatus::Booked)
            ->exists();

        if (! $holdsSeat) {
            return false;
        }

        /*
        | ⛔ **وبدونِ هذا السطرِ يدومُ «إخراج» تحديثةً واحدة.** أُبلِغَ من حصّةٍ
        | حقيقيّةٍ في ٢٠٢٦-٠٨-٢٦: كانَ الإخراجُ نداءً للمزوّدِ ولا شيءَ غيرَه،
        | فيُفصَلُ الطالبُ ثمّ يعودُ بعدَ ثانية.
        */
        if ($this->wasRemoved($session, $user)) {
            return false;
        }

        // غرفةٌ لم تُفتَحْ بعدُ: الطالبُ لا يفتحُها، والمضيفُ وحدَه من يفعل.
        return $session->broadcast_room_id !== null;
    }

    /**
     * هل أخرجَ المضيفُ هذا الشخصَ من هذه الحصّة؟
     *
     * `withoutWorkspaceScope()` للسببِ الذي تحتاجُه كلُّ قراءةٍ في مسارِ الطالب:
     * سياقُه فارغٌ فالنطاقُ لا يضيفُ شرطاً أصلاً — والسجلُّ يُقرَأُ كذلك من طلبِ
     * المدرّس، حيثُ يُضيفُ النطاقُ مساحةً أخرى فلا يجدُ شيئاً.
     */
    private function wasRemoved(ClassSession $session, User $user): bool
    {
        return Attendance::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $user->getKey())
            ->whereNotNull('removed_at')
            ->exists();
    }
}
