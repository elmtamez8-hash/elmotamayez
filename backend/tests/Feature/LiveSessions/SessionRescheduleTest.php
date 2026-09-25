<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\ExpireSessionRescheduleRequestsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| طلبُ تأجيلِ حصّةٍ واحدةٍ بموافقةِ المدرّس.
|
| ⚠️ «مؤقّتة» لا تحتاجُ آليّةً من أيِّ نوع، والحالةُ الأولى هنا هي البرهان: الحصصُ
| صفوفٌ لها تواريخُها منذُ ٠٤٨، فنقلُ واحدةٍ تعديلُ صفٍّ واحدٍ والتاليةُ صفٌّ لم
| يُمَسّ. لا علَمَ «مؤجّلة»، ولا جدولَ استثناءات، ولا مهمّةَ إعادةٍ إلى الموعدِ الأصليّ.
|
| ⚠️ والفحصُ الذي يبيتُ عليه كلُّ شيء: الموافقةُ تمرُّ عبرَ `UpdateClassSession`،
| فتصادمُ المواعيدِ وفترةُ التجميدِ مفحوصانِ حيثُ يُكتَبُ التقويمُ فعلاً. موافقةٌ
| تُسقِطُ سبتَ مجموعةٍ فوقَ سبتِ أخرى تُرفَضُ، **والطلبُ يبقى قائماً** — لأنّ الطلبَ
| لم يحجزْ شيئاً، فخسارتُه لا تكلّفُ الطالبَ شيئاً أيضاً.
*/

/**
 * A group course with two lessons a week apart and two students in both.
 *
 * @return array<string, mixed>
 */
function rescheduleFixture(): array
{
    /** @var TestCase $test */
    $test = test();

    app()->forgetInstance(WorkspaceContext::class);

    [$workspace, $owner] = $test->createWorkspaceWithOwner();
    $test->setCurrentWorkspace($workspace, $owner);

    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
    ]);

    $course = Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
        'teacher_profile_id' => $profile->getKey(),
        'course_type' => Course::TYPE_GROUP,
        'title' => 'الفيزياء',
    ]);

    $cohortId = groupCohortIdFor((int) $course->getKey(), (int) $workspace->getKey());

    // A fixed weekday next week: deterministic, always future, and never today —
    // an hour derived from `now()` is in the past for half of every run.
    $first = CarbonImmutable::now()->utc()->addWeek()->startOfWeek()->addDays(5)->setTime(13, 0);

    $make = fn (CarbonImmutable $at, string $title): ClassSession => app(ScheduleClassSession::class)
        ->handle(new ScheduleSessionData(
            teacherProfileId: (int) $profile->getKey(),
            title: $title,
            type: ClassSessionType::Group,
            startsAt: $at,
            durationMinutes: 60,
            seatsTotal: 6,
            courseId: (int) $course->getKey(),
            cohortId: $cohortId,
        ), $owner);

    $saturday = $make($first, 'سبت الأسبوع');
    $nextSaturday = $make($first->addWeek(), 'سبت الأسبوع التالي');

    $students = collect(range(1, 2))->map(function () use ($workspace, $course, $saturday, $nextSaturday): User {
        $student = User::factory()->create();

        enrolInCourse($workspace, $course, $student);
        fundBooking($workspace, $student, $course, 10);
        // A seat in a group's session goes to a member of that group.
        joinTestGroup($student, (int) $course->getKey(), (int) $workspace->getKey());

        app(BookSeat::class)->handle($saturday, $student);
        app(BookSeat::class)->handle($nextSaturday, $student);

        return $student;
    })->all();

    return [
        'workspace' => $workspace,
        'owner' => $owner,
        'course' => $course,
        'saturday' => $saturday,
        'nextSaturday' => $nextSaturday,
        'students' => $students,
        'proposed' => $first->addDay()->setTime(15, 0),
    ];
}

/** @param array<string, mixed> $fixture */
function askToMove(array $fixture, ?CarbonImmutable $to = null): TestResponse
{
    Sanctum::actingAs($fixture['students'][0]);

    return test()->postJson("/api/v1/class-sessions/{$fixture['saturday']->uuid}/reschedule-requests", [
        'to_starts_at' => ($to ?? $fixture['proposed'])->toIso8601String(),
        'reason' => 'عندي امتحان ذلك اليوم',
    ]);
}

/** @param array<string, mixed> $fixture */
function decideMove(array $fixture, bool $approve, ?string $reason = null): TestResponse
{
    Sanctum::actingAs($fixture['owner']);

    $uuid = SessionRescheduleRequest::query()->withoutWorkspaceScope()->pending()->firstOrFail()->uuid;

    return test()->postJson("/api/v1/manage/session-reschedule-requests/{$uuid}/decide", array_filter([
        'approve' => $approve,
        'decision_reason' => $reason,
    ], fn ($value) => $value !== null));
}

beforeEach(function (): void {
    /*
    | ⚠️ الحصصُ وحدَها. `Queue::fake()` عاريةً تبتلعُ المستمعينَ المصفوفين، فتصيرُ
    | كلُّ دعوى إشعارٍ هنا ادّعاءً واثقاً عن جدولٍ فارغ — ودونَها يُنفَّذُ
    | `CloseClassSessionJob` فوراً على وصلةِ `sync` فيُغلِقُ الحصّةَ قبلَ أن تُحجَز.
    */
    fakeSessionTimeline();
});

it('moves the asked-for lesson and leaves the next one exactly where it was', function (): void {
    $fixture = rescheduleFixture();

    askToMove($fixture)->assertStatus(201);

    $nextBefore = $fixture['nextSaturday']->starts_at->toIso8601String();

    decideMove($fixture, approve: true)
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    /*
    | ⚠️ هذا مطلبُ «والحصّةُ التاليةُ في موعدِها الطبيعيّ» كلُّه، ولا سطرَ في الشجرةِ
    | يُنفِّذُه: الصفُّ الثاني لم يُمَسّ لأنّه صفٌّ آخر. لو كانت المواعيدُ مراجعَ إلى
    | شقوقِ توفّرٍ أسبوعيّةٍ لتحرّكَ الأسبوعانِ معاً.
    */
    expect($fixture['saturday']->fresh()->starts_at->toIso8601String())
        ->toBe($fixture['proposed']->toIso8601String())
        ->and($fixture['nextSaturday']->fresh()->starts_at->toIso8601String())->toBe($nextBefore);
});

it('tells the teacher a student asked to move a lesson, and nobody else', function (): void {
    $fixture = rescheduleFixture();

    askToMove($fixture)->assertCreated();

    // The request is the teacher's to decide, so it lands in their feed — not in
    // the other seat holder's, who has been asked nothing yet.
    assertNotifiedOnce($fixture['owner'], NotificationType::SessionRescheduleRequested);

    expect(wasNotified($fixture['students'][1], NotificationType::SessionRescheduleRequested))->toBeFalse();
});

it('tells every seat holder the new time, not only the one who asked', function (): void {
    $fixture = rescheduleFixture();

    askToMove($fixture);
    decideMove($fixture, approve: true)->assertOk();

    $told = Notification::query()
        ->where('type', NotificationType::SessionRescheduled->value)
        ->pluck('recipient_user_id')
        ->map(fn ($id): int => (int) $id)
        ->sort()
        ->values()
        ->all();

    // The classmate asked for nothing and their Saturday has moved; a message to
    // the requester alone leaves them turning up to an empty room.
    $expected = collect($fixture['students'])
        ->map(fn (User $student): int => (int) $student->getKey())
        ->sort()
        ->values()
        ->all();

    expect($told)->toBe($expected);
});

it('refuses an approval that would drop the lesson on another, and leaves the request pending', function (): void {
    $fixture = rescheduleFixture();

    // The hour proposed is the hour this teacher already teaches next week.
    askToMove($fixture, CarbonImmutable::instance($fixture['nextSaturday']->starts_at))->assertStatus(201);

    $was = $fixture['saturday']->starts_at->toIso8601String();

    decideMove($fixture, approve: true)->assertStatus(422);

    /*
    | ⚠️ الطلبُ يبقى قائماً والحصّةُ في مكانِها. لو سُوِّيَ الطلبُ قبلَ النقلِ لبقيَ
    | صفٌّ «مقبول» فوقَ حصّةٍ لم تتحرّك — وهو ما يشيرُ إليه الطالبُ ولا يستطيعُ أحدٌ
    | أن يُنفِّذَه.
    */
    expect($fixture['saturday']->fresh()->starts_at->toIso8601String())->toBe($was)
        ->and(SessionRescheduleRequest::query()->withoutWorkspaceScope()->firstOrFail()->status)->toBe('pending');
});

it('refuses a second live request on one lesson, and frees the slot once the first is decided', function (): void {
    $fixture = rescheduleFixture();

    askToMove($fixture)->assertStatus(201);

    Sanctum::actingAs($fixture['students'][1]);

    $second = fn (): TestResponse => test()->postJson(
        "/api/v1/class-sessions/{$fixture['saturday']->uuid}/reschedule-requests",
        ['to_starts_at' => $fixture['proposed']->addHour()->toIso8601String()],
    );

    // Two classmates proposing two Sundays would leave the teacher two buttons
    // that move the same lesson twice.
    $second()->assertStatus(422);

    decideMove($fixture, approve: false, reason: 'الأحد عندي مجموعة أخرى.')->assertOk();

    /*
    | ⚠️ الحالةُ الحاملةُ للثقل. لو لم يتحرّكْ `pending_slot` عن صفرِه عندَ البتّ
    | لظلَّ الفهرسُ الفريدُ يرفضُ كلَّ طلبٍ لاحقٍ على هذه الحصّةِ إلى الأبد — والحالتانِ
    | فوقَها تمرّانِ على بناءٍ لا يُحرِّرُ الشقَّ إطلاقاً.
    */
    Sanctum::actingAs($fixture['students'][1]);
    $second()->assertStatus(201);
});

it('refuses somebody with no seat in the lesson', function (): void {
    $fixture = rescheduleFixture();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/class-sessions/{$fixture['saturday']->uuid}/reschedule-requests", [
        'to_starts_at' => $fixture['proposed']->toIso8601String(),
    ])->assertStatus(422);

    expect(SessionRescheduleRequest::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a rejection with no reason, and tells only the asker when there is one', function (): void {
    $fixture = rescheduleFixture();

    askToMove($fixture);

    // A silent refusal is indistinguishable from a request still waiting, and is
    // submitted again for ever — a queue the teacher then clears twice.
    decideMove($fixture, approve: false)->assertStatus(422);

    decideMove($fixture, approve: false, reason: 'الأحد عندي مجموعة أخرى.')->assertOk();

    $told = Notification::query()
        ->where('type', NotificationType::SessionRescheduleRejected->value)
        ->pluck('recipient_user_id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    // Nothing moved, so the group was never in this conversation.
    expect($told)->toBe([(int) $fixture['students'][0]->getKey()]);
});

it('keeps one teacher out of another workspace queue', function (): void {
    $fixture = rescheduleFixture();

    askToMove($fixture);

    app()->forgetInstance(WorkspaceContext::class);
    [$otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($otherWorkspace, $otherOwner);

    Sanctum::actingAs($otherOwner);

    $this->getJson('/api/v1/manage/session-reschedule-requests')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $uuid = SessionRescheduleRequest::query()->withoutWorkspaceScope()->firstOrFail()->uuid;

    $this->postJson("/api/v1/manage/session-reschedule-requests/{$uuid}/decide", ['approve' => true])
        ->assertStatus(403);
});

/*
| ⛔ A request sitting in the queue does not stay true (2026-09-24). The clocks
| are asked when the teacher ANSWERS, not only when the student asks, and an
| unanswered request stops holding its lesson once its moment has passed.
*/
it('refuses to approve a move to an hour that has already gone', function (): void {
    $fixture = rescheduleFixture();

    // The day BEFORE the lesson: future when asked, past when answered, while
    // the lesson itself has not begun — so only the target clock can refuse.
    $target = CarbonImmutable::instance($fixture['saturday']->starts_at)->subDay()->setTime(15, 0);
    askToMove($fixture, $target)->assertCreated();

    $this->travelTo($target->addMinute());

    decideMove($fixture, approve: true)
        ->assertStatus(422)
        ->assertJsonPath('message', 'الموعد المقترح للتأجيل قد مضى. اطلب من الطالب اقتراح موعد جديد.');

    expect($fixture['saturday']->fresh()->starts_at->toIso8601String())
        ->toBe($fixture['saturday']->starts_at->toIso8601String());
});

it('refuses to approve a move once the lesson itself has begun', function (): void {
    $fixture = rescheduleFixture();

    askToMove($fixture)->assertCreated();

    $this->travelTo(CarbonImmutable::instance($fixture['saturday']->starts_at)->addMinutes(10));

    decideMove($fixture, approve: true)
        ->assertStatus(422)
        ->assertJsonPath('message', 'بدأ موعد الحصة الأصلي بالفعل، فلم يعد تأجيلها ممكناً.');
});

it('expires an unanswered request at its moment and frees the lesson to be asked about again', function (): void {
    $fixture = rescheduleFixture();

    $target = CarbonImmutable::instance($fixture['saturday']->starts_at)->subDay()->setTime(15, 0);
    askToMove($fixture, $target)->assertCreated();

    // Not yet: a request whose moment has not come is left alone.
    app(ExpireSessionRescheduleRequestsJob::class)->handle();
    expect(SessionRescheduleRequest::query()->withoutWorkspaceScope()->sole()->status)->toBe('pending');

    $this->travelTo($target->addMinute());
    app(ExpireSessionRescheduleRequestsJob::class)->handle();

    expect(SessionRescheduleRequest::query()->withoutWorkspaceScope()->sole()->status)
        ->toBe(SessionRescheduleRequest::EXPIRED);

    // `srr_pending_unique` is free again: a fresh ask about the same lesson lands.
    askToMove($fixture, CarbonImmutable::instance($fixture['saturday']->starts_at)->addDay())->assertCreated();
});

/*
| ⚠️ AN EXPIRY USED TO TELL NOBODY — the student found «منتهٍ» on their timetable
| or did not find it. The sweep now sends ONE notice to the asker, behind the
| settle that won, and a second pass over the same row says nothing.
*/
it('tells the asker once when their request expires, and says nothing on a second sweep', function (): void {
    $fixture = rescheduleFixture();

    $target = CarbonImmutable::instance($fixture['saturday']->starts_at)->subDay()->setTime(15, 0);
    askToMove($fixture, $target)->assertCreated();

    $expiryNotices = fn () => Notification::query()
        ->where('type', NotificationType::SessionRescheduleExpired->value)
        ->get();

    // Still pending: no notice before its moment.
    app(ExpireSessionRescheduleRequestsJob::class)->handle();
    expect($expiryNotices())->toHaveCount(0);

    $this->travelTo($target->addMinute());
    app(ExpireSessionRescheduleRequestsJob::class)->handle();

    $notices = $expiryNotices();

    // Exactly one, to the asker alone — the classmates hold the same seat and
    // were never in this conversation.
    expect($notices)->toHaveCount(1)
        ->and((int) $notices->first()->recipient_user_id)->toBe((int) $fixture['students'][0]->getKey())
        ->and($notices->first()->action_url)->toBe('/schedule');

    // A second sweep over the already-expired row matches nothing and says nothing.
    app(ExpireSessionRescheduleRequestsJob::class)->handle();

    expect($expiryNotices())->toHaveCount(1);
});

it('sends no expiry notice when the teacher answered first', function (): void {
    $fixture = rescheduleFixture();

    askToMove($fixture)->assertCreated();
    decideMove($fixture, approve: false, reason: 'الأحد عندي مجموعة أخرى.')->assertOk();

    // Past every deadline the request could have had.
    $this->travelTo(CarbonImmutable::instance($fixture['saturday']->starts_at)->addHour());
    app(ExpireSessionRescheduleRequestsJob::class)->handle();

    expect(Notification::query()->where('type', NotificationType::SessionRescheduleExpired->value)->count())
        ->toBe(0);
});

it('lets a new ask through without waiting for the sweep when the old one is overdue', function (): void {
    $fixture = rescheduleFixture();

    $target = CarbonImmutable::instance($fixture['saturday']->starts_at)->subDay()->setTime(15, 0);
    askToMove($fixture, $target)->assertCreated();

    $this->travelTo($target->addMinute());

    // No sweep has run: the overdue request is settled by the ask itself.
    askToMove($fixture, CarbonImmutable::instance($fixture['saturday']->starts_at)->addDay())->assertCreated();

    expect(SessionRescheduleRequest::query()->withoutWorkspaceScope()->pluck('status')->sort()->values()->all())
        ->toBe([SessionRescheduleRequest::EXPIRED, SessionRescheduleRequest::PENDING]);
});

/*
| A proposed hour carries the same minimum notice a private request does.
|
| ⛔ «NOT IN THE PAST» ALONE LET A STUDENT PROPOSE AN HOUR TEN MINUTES AWAY — a
| request the teacher cannot read, decide and prepare for before it begins. One
| class (`LeadTime`) answers «too soon» for both doors.
*/
it('refuses a proposed hour inside the minimum notice, and nothing is written', function (): void {
    $fixture = rescheduleFixture();

    askToMove($fixture, CarbonImmutable::now()->addMinutes(30))
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'اختر موعداً يبدأ بعد ساعتين من الآن على الأقل، ليتّسع للمدرّس أن يردّ ويستعدّ.']);

    expect(SessionRescheduleRequest::query()->withoutWorkspaceScope()->count())->toBe(0);
});
