<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| ٠٢٩ · `FR-011أ` — الرقمُ الذي يجعلُ زرَّ الدخولِ يظهرُ بلا إعادةِ تحميل.
|
| ⚠️ `join_open` يُجابُ **مرّةً واحدةً عندَ الجلب**. فطالبٌ يفتحُ جدولَه قبلَ
| الحصّةِ بعشرينَ دقيقةً يرى العدَّ التنازليَّ يبلغُ «بدأت الآن» والبابُ مغلقٌ
| أمامَه إلى أن يُعيدَ التحميل. والمتصفّحُ يجوزُ له أن يُنقِصَ رقماً وصلَه، ولا
| يجوزُ له أن يشتقَّه من `starts_at` ناقصَ ثابت: النافذةُ صفٌّ في
| `platform_settings` يضبطُه المشغّل، وساعةُ الجهازِ قد تكونُ بساعةٍ كاملةٍ خطأ.
|
| ⚠️ وتهجئةٌ واحدةٌ: `ScheduleController` كان يحملُ الحسابَ خاصّاً لترويسةِ الكورس،
| وصارَ على النموذجِ يقرؤُه الاثنان.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->profile = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    // طالبٌ بالشكلِ الحقيقيّ: لا مساحةَ عملٍ محفوظةً له.
    $this->student = User::factory()->create(['last_workspace_id' => null]);
});

function bookedSessionAt(CarbonImmutable $startsAt, ?CarbonImmutable $roomClosedAt = null): ClassSession
{
    $test = test();

    $session = ClassSession::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'teacher_profile_id' => $test->profile->getKey(),
        'course_id' => $test->course->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'duration_minutes' => 60,
        'seats_total' => 5,
        'room_closed_at' => $roomClosedAt,
    ]);

    SessionBooking::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $test->student->getKey(),
    ]);

    return $session;
}

function readSchedule(): TestResponse
{
    test()->asGuest();
    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs(test()->student);

    return test()->getJson('/api/v1/schedule');
}

it('counts the seconds down to the door on every row of the timetable', function (): void {
    $window = app(SessionSettings::class)->joinWindowMinutes();

    // بعدَ ساعتَين: البابُ يفتحُ قبلَ البدايةِ بمقدارِ النافذة.
    bookedSessionAt(CarbonImmutable::now()->addHours(2));

    $response = readSchedule()->assertOk()->assertJsonCount(1, 'data');

    $seconds = $response->json('data.0.session.seconds_until_join_open');
    $expected = 2 * 3600 - $window * 60;

    expect($seconds)->toBeInt()
        ->toBeGreaterThan($expected - 120)
        ->toBeLessThanOrEqual($expected)
        // والبابُ ما زالَ مغلقاً: الرقمُ هو الفرقُ بينَ الحالتَين.
        ->and($response->json('data.0.session.join_open'))->toBeFalse();

    // والعدُّ إلى البدايةِ من الخادمِ كذلك — لا من ساعةِ المتصفّح.
    expect($response->json('data.0.session.seconds_until_start'))
        ->toBeGreaterThan(2 * 3600 - 120)
        ->toBeLessThanOrEqual(2 * 3600);
});

it('answers zero while the door is open, which is what makes the button appear', function (): void {
    bookedSessionAt(CarbonImmutable::now()->addMinutes(2));

    $response = readSchedule()->assertOk();

    expect($response->json('data.0.session.seconds_until_join_open'))->toBe(0)
        ->and($response->json('data.0.session.join_open'))->toBeTrue();
});

it('answers null for a room that was closed, never a countdown to a shut door', function (): void {
    // ⚠️ الغرفةُ تُغلَقُ قبلَ أن تلحقَ بها الحالة: مدرّسٌ أنهى البثَّ مبكّراً
    // يتركُ الحصّةَ `live` وغرفتَها مغلقة. عدٌّ تنازليٌّ هنا دعوةٌ إلى بابٍ
    // يُجيبُ «تعذّر الدخول».
    bookedSessionAt(CarbonImmutable::now()->addMinutes(5), CarbonImmutable::now());

    expect(readSchedule()->assertOk()->json('data.0.session.seconds_until_join_open'))->toBeNull();
});

it('answers null once the window has passed for good', function (): void {
    $window = app(SessionSettings::class)->joinWindowMinutes();

    bookedSessionAt(CarbonImmutable::now()->subHours(3)->subMinutes($window + 30));

    // الحصّةُ الماضيةُ خارجَ الجدولِ أصلاً — فالتوكيدُ على النموذجِ مباشرةً،
    // وهو نفسُ ما يقرؤُه المَورِد.
    $past = ClassSession::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    expect($past->secondsUntilJoinOpen(now()))->toBeNull();
});
