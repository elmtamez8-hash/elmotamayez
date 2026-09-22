<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Actions\IssueJoinTicket;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Support\Facades\Gate;

/**
 * «هل ما زالَ من حقِّ هذا الشخصِ أن يكونَ في الغرفةِ **الآن**؟»
 *
 * ⛔ **وهذا سؤالٌ غيرُ «هل يُسمَحُ له بالدخول»، والخلطُ بينَهما كلّفَ النبضةَ
 * خمسةَ عشرَ استعلاماً بدلَ خمسة.**
 *
 * المزوّدُ لا يملكُ سحبَ تذكرة، ويُعيدُ إنشاءَ غرفةٍ محذوفةٍ عندَ أوّلِ دخول —
 * فأداتُنا الوحيدةُ لإخراجِ أحدٍ هي إعادةُ السؤالِ في كلِّ نبضةٍ والرفض. وذلكَ
 * السؤالُ **لازمٌ ولا يُحذَف** (`TicketAfterCloseTest` يكتبُه). لكنّ
 * `BroadcastController::presence()` كانَ يُعيدُ سلسلةَ **الباب** كاملةً:
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
        /*
        | ⚠️ **السياسةُ لا صيغةٌ ثالثة.** كانَ هذا السطرُ يُعيدُ بناءَ الشرطِ
        | بيدِه — `can(SESSIONS_HOST)` مع مقارنةِ `last_workspace_id` — بينما
        | `BroadcastController` يسألُ `Gate::allows('host')` لأفعالِ المضيفِ
        | ولقائمةِ المشتركين. والاثنانِ يختلفانِ فعلاً على مديرِ المنصّة:
        | `Gate::before` يمرِّرُه فوقَ كلِّ سياسة، ومقارنةُ العمودِ ترفضُه. صيغتانِ
        | لسؤالٍ واحدٍ تضعانِ جواباً على الشاشةِ وآخرَ على الباب — العطلُ الذي
        | دفعَه هذا المستودعُ في `BookingEligibility` و`ListLeaderboardScopes`.
        |
        | `forUser` لا `allows` المجرّدة: السؤالُ عن صاحبِ النبضةِ المُمرَّرِ
        | إلينا، لا عمّن يحملُ الطلبَ — وهما واحدٌ اليوم ولا يلزمُ أن يبقيا.
        */
        return Gate::forUser($user)->allows('host', $session);
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
        if (! $session->joinWindowCovers(now()) || ! $this->statusAdmits($session)) {
            return false;
        }

        if ($this->isHost($session, $user)) {
            return true;
        }

        // مقعدٌ حُرِّرَ — بإلغاءِ الطالبِ أو بكنسِ المقاعدِ غيرِ المستحقّة — يُخرِج.
        // ⚠️ Unscoped: the reader may be a student stamped with another
        // teacher's workspace, and the scope would hide their own seat.
        $holdsSeat = $session->bookings()
            ->withoutWorkspaceScope()
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
     * هل حالةُ الحصّةِ تسمحُ لأحدٍ بأن يكونَ في الغرفةِ أصلاً؟
     *
     * ⛔ **و«الموقوفة» ليست حالةً نهائيّةً، وإغفالُها كانَ تراجعاً أدخلَه هذا
     * التقسيمُ نفسُه.** `isTerminal()` هي «منتهية» أو «ملغاة» وحدَهما، بينما
     * `OpenBroadcastRoom` يرفضُ `Suspended` **في سطرٍ مستقلٍّ بجوارِ**
     * `isTerminal()` — وقبلَ التقسيمِ كانت النبضةُ تمرُّ بذلكَ الفعلِ فتأخذُ
     * الرفضَينِ معاً. بعدَه صارَت تسألُ هنا وحدَها، فحصّةٌ أوقفَتها فترةُ تجميدٍ
     * تردُّ ٢٠٠ على نبضةٍ كانت تردُّ ٤٠٣.
     *
     * والتجميدُ **يُوقِفُ ولا يُلغي** (٠٠٥ · FR-043)، فالحالتانِ سؤالانِ
     * مختلفانِ ولا تُدمَجانِ في واحدة — تماماً كما أنّ `CancelClassSession`
     * لا يختمُ `room_closed_at` فلا تكفي النافذةُ وحدَها.
     */
    private function statusAdmits(ClassSession $session): bool
    {
        return ! $session->status->isTerminal()
            && $session->status !== ClassSessionStatus::Suspended;
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
