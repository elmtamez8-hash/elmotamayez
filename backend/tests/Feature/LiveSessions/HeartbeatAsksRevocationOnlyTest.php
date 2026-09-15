<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **النبضةُ تسألُ ما يُخرِج، والبابُ يسألُ ما يُدخِل.**
|
| المزوّدُ لا يسحبُ تذكرةً ويُعيدُ إنشاءَ غرفةٍ محذوفةٍ عندَ أوّلِ دخول، فإعادةُ
| السؤالِ في كلِّ نبضةٍ هي أداتُنا الوحيدةُ للإخراج — **ولا تُحذَف**. وكانت
| النبضةُ تُعيدُ سلسلةَ البابِ كاملةً: خمسةَ عشرَ استعلاماً مرّتَينِ في الدقيقةِ
| لكلِّ مشترك، وطالبةٌ اضطربَ رصيدُها في منتصفِ الشرحِ تُرمى خارجَ حصّةٍ دفعَت
| ثمنَها.
|
| فهذا الملفُّ يكتبُ الحدَّ: أربعةُ أسبابٍ تُخرِج، وسببٌ **لا يُخرِج** — والأخيرُ
| هو تغييرُ الاستحقاقِ المقصود، ومعه ضابطُه: البابُ نفسُه ما زالَ يرفضُه.
|
| **كيفَ يمسك**: أعِدْ `presence()` إلى `IssueJoinTicket::handle()` ⇒ يسقطُ
| «المالُ لا يُخرِجُ من حصّةٍ جارية» بـ«٤٠٣ بدلَ ٢٠٠».
*/

beforeEach(function (): void {
    // ⚠️ التأجيلُ يعملُ فوراً على `sync`، فالمهمّةُ تختمُ `room_closed_at` داخلَ
    // الفتحِ نفسِه ويصيرُ كلُّ بابٍ بعدَها ٤٠٣ لسببٍ لا علاقةَ له بما نقيس.
    Queue::fake([CloseClassSessionJob::class]);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    fundBooking($this->workspace, $this->student, $this->course);

    app(BookSeat::class)->handle($this->session, $this->student);
    app(OpenBroadcastRoom::class)->handle($this->session);

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $this->beat = fn () => $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/presence");
    $this->door = fn () => $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join");
});

it('lets a seated student keep pinging', function (): void {
    // الضابطُ الموجَب: بناءٌ يرفضُ كلَّ نبضةٍ يُرضي كلَّ ما تحتَه إرضاءً تامّاً.
    ($this->beat)()->assertOk();
});

/*
| ⛔ الأربعةُ التي **تُخرِج**. كلُّ واحدٍ منها يتغيّرُ أثناءَ الحصّةِ فعلاً،
| ولكلٍّ منها عطلٌ حقيقيٌّ مسجَّلٌ حينَ كانَ ناقصاً.
*/
it('evicts a student the host removed', function (): void {
    Attendance::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->update(['removed_at' => now()]);

    ($this->beat)()->assertForbidden()->assertJsonPath('code', 'session_not_joinable');
});

it('evicts a student whose seat was released', function (): void {
    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->update(['status' => BookingStatus::Released]);

    ($this->beat)()->assertForbidden();
});

/*
| ⚠️ **والملغاةُ سببٌ قائمٌ بذاتِه، لا يكفي فيه سؤالُ النافذة.**
| `CancelClassSession` **لا يختمُ `room_closed_at`**، فحصّةٌ ألغاها المدرّسُ
| تمرُّ من `joinWindowCovers()` مروراً تامّاً. وهذا الشقُّ هو ما أسقطَ أوّلَ صيغةٍ
| من هذا التقسيم — ردَّتِ النبضةُ ٢٠٠ على حصّةٍ ملغاة.
*/
it('evicts everyone from a cancelled session', function (): void {
    $this->session->forceFill(['status' => ClassSessionStatus::Cancelled])->save();

    ($this->beat)()->assertForbidden();
});

it('evicts everyone once the room has been closed', function (): void {
    $this->session->forceFill(['room_closed_at' => now()])->save();

    ($this->beat)()->assertForbidden();
});

/*
| ⛔ **والذي لا يُخرِج: المال.**
|
| رصيدٌ نفد أثناءَ الحصّةِ سؤالُ **الحجزِ القادم**، لا سؤالُ الساعةِ الجارية.
| وقبلَ التقسيمِ كانت النبضةُ التاليةُ ترمي الطالبةَ خارجَ حصّةٍ **دفعَت ثمنَها**،
| في منتصفِ شرحٍ لا تستطيعُ إعادتَه — وذلكَ لم يطلبْه متطلَّبٌ قطُّ، بل كانَ أثراً
| جانبيّاً لإعادةِ استعمالِ دالّةِ البابِ بحالِها في النبضة.
*/
it('does not evict a paying student whose credit ran out mid-lesson', function (): void {
    CreditBalance::query()->withoutWorkspaceScope()
        ->where('course_id', $this->course->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->update(['remaining_credits' => 0, 'held_credits' => 0]);

    ($this->beat)()->assertOk();
});

/*
| ⚠️ **وضابطُه: البابُ لم يُفتَحْ معَها.** بدونَ هذا الشقِّ يمرُّ بناءٌ حذفَ
| شرطَ المالِ من الطرفَينِ — أي رخّصَ ما كانَ يمنعُه بدلَ أن يُؤجِّلَه إلى الحصّةِ
| التالية.
*/
it('still refuses the same student at the door', function (): void {
    CreditBalance::query()->withoutWorkspaceScope()
        ->where('course_id', $this->course->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->update(['remaining_credits' => 0, 'held_credits' => 0]);

    ($this->door)()->assertForbidden();
});
