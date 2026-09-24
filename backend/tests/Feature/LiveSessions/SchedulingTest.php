<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\GenerateSessionsFromAvailability;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Actions\UpdateClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    // Every session belongs to a course since Q-7 (spec 006): the price is a
    // property of the course, so a session with no course is a session with no
    // price and could never consume a credit.
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
});

function scheduleData(TeacherProfile $teacher, CarbonImmutable $startsAt, int $minutes = 60): ScheduleSessionData
{
    $courseId = (int) Course::query()->where('workspace_id', $teacher->workspace_id)->value('id');

    return new ScheduleSessionData(
        teacherProfileId: (int) $teacher->getKey(),
        courseId: $courseId,
        title: 'حصة رياضيات',
        type: ClassSessionType::Group,
        startsAt: $startsAt,
        durationMinutes: $minutes,
        seatsTotal: 8,
        // A group lesson names its group from the moment it is created.
        cohortId: groupCohortIdFor($courseId, (int) $teacher->workspace_id),
    );
}

it('schedules a session on the calendar', function (): void {
    $session = app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, CarbonImmutable::now()->addDay()->startOfHour()),
        $this->owner,
    );

    expect($session->status)->toBe(ClassSessionStatus::Scheduled)
        ->and($session->seats_taken)->toBe(0)
        // Signed in Carbon 3, so the order is the assertion: start then end.
        ->and($session->starts_at->diffInMinutes($session->ends_at))->toEqual(60.0);
});

// SC-002 · FR-003.
it('refuses a session that overlaps another of the same teacher', function (): void {
    $startsAt = CarbonImmutable::now()->addDay()->startOfHour();

    app(ScheduleClassSession::class)->handle(scheduleData($this->teacher, $startsAt), $this->owner);

    expect(fn () => app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, $startsAt->addMinutes(30)),
        $this->owner,
    ))->toThrow(DomainException::class);
});

// Back-to-back teaching is how a full day is actually taught, so touching
// boundaries must not read as a clash.
it('allows a session that starts exactly when another ends', function (): void {
    $startsAt = CarbonImmutable::now()->addDay()->startOfHour();

    app(ScheduleClassSession::class)->handle(scheduleData($this->teacher, $startsAt), $this->owner);
    $second = app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, $startsAt->addMinutes(60)),
        $this->owner,
    );

    expect($second->exists)->toBeTrue();
});

it('refuses a session whose seat count contradicts its type', function (): void {
    $data = new ScheduleSessionData(
        teacherProfileId: (int) $this->teacher->getKey(),
        courseId: (int) $this->course->getKey(),
        title: 'حصة',
        type: ClassSessionType::Individual,
        startsAt: CarbonImmutable::now()->addDay(),
        durationMinutes: 60,
        // Individual means exactly one seat — inferring the type from this number
        // afterwards is what FR-001أ forbids.
        seatsTotal: 5,
    );

    expect(fn () => app(ScheduleClassSession::class)->handle($data, $this->owner))
        ->toThrow(DomainException::class);
});

it('refuses to schedule inside a freeze period', function (): void {
    FreezePeriod::factory()->create([
        'starts_on' => now()->addDays(1)->toDateString(),
        'ends_on' => now()->addDays(10)->toDateString(),
        'created_by' => $this->owner->getKey(),
    ]);

    expect(fn () => app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, CarbonImmutable::now()->addDays(3)->startOfHour()),
        $this->owner,
    ))->toThrow(DomainException::class);
});

describe('generating from availability', function (): void {
    it('turns weekly slots into dated sessions', function (): void {
        $tomorrow = CarbonImmutable::now()->utc()->addDay();

        AvailabilitySlot::factory()->create([
            'teacher_profile_id' => $this->teacher->getKey(),
            'day_of_week' => (int) $tomorrow->format('w'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $result = app(GenerateSessionsFromAvailability::class)->handle(
            $this->teacher,
            $this->course,
            $tomorrow->startOfDay(),
            $tomorrow->addDays(6),
            $this->owner,
        );

        expect($result['created'])->toHaveCount(1)
            ->and($result['created'][0]->duration_minutes)->toBe(60);
    });

    // A generator that silently drops clashes leaves a teacher believing their
    // week is full when half of it was never created.
    it('reports what it skipped and why', function (): void {
        $tomorrow = CarbonImmutable::now()->utc()->addDay();

        AvailabilitySlot::factory()->create([
            'teacher_profile_id' => $this->teacher->getKey(),
            'day_of_week' => (int) $tomorrow->format('w'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        app(ScheduleClassSession::class)->handle(
            scheduleData($this->teacher, CarbonImmutable::parse($tomorrow->toDateString().' 10:00:00', 'UTC')),
            $this->owner,
        );

        $result = app(GenerateSessionsFromAvailability::class)->handle(
            $this->teacher,
            $this->course,
            $tomorrow->startOfDay(),
            $tomorrow->addDays(6),
            $this->owner,
        );

        expect($result['created'])->toHaveCount(0)
            ->and($result['skipped'])->toHaveCount(1)
            ->and($result['skipped'][0]['reason'])->not->toBe('');
    });
});

// FR-001ب. Pricing in 006 and payout in 014 differ by type in kind, so flipping
// a booked group session silently reprices seats people already hold.
it('refuses to change the type once a seat is booked', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $session = ClassSession::factory()->create(['teacher_profile_id' => $this->teacher->getKey()]);
    SessionBooking::factory()->create([
        'class_session_id' => $session->getKey(),
        'student_user_id' => $student->getKey(),
    ]);
    $session->update(['seats_taken' => 1]);

    Sanctum::actingAs($this->owner);

    $this->putJson("/api/v1/class-sessions/{$session->uuid}", [
        'type' => ClassSessionType::Individual->value,
    ])->assertStatus(422);
});

/*
| ⚠️ المواعيدُ تُفحَصُ عندَ التعديلِ كما تُفحَصُ عندَ الإنشاء — ولم تكنْ.
|
| `ScheduleClassSession` يرفضُ التداخلَ وفترةَ التجميدِ منذُ كُتِب؛ و
| `UpdateClassSession` كان ينقلُ `starts_at` بلا فحصٍ من الاثنين. فالقاعدةُ تصمدُ
| أثناءَ الإنشاءِ وتتبخّرُ عندَ أوّلِ نقل — وهكذا يقعُ سبتُ مجموعةٍ فوقَ سبتِ مجموعةٍ
| أخرى: غرفتانِ من الطلابِ تُدعَيانِ إلى الساعةِ نفسِها، ولا شيءَ يقولُ ذلك.
|
| والتصادمُ لكلِّ **مدرّس** لا لكلِّ مجموعة: المدرّسُ لا يكونُ في غرفتين معاً.
*/
it('refuses to move a session on top of another group\'s hour', function (): void {
    $at = CarbonImmutable::now()->addDays(2)->startOfHour();

    $first = app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, $at),
        $this->owner,
    );

    $second = app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, $at->addHours(3)),
        $this->owner,
    );

    expect(fn () => app(UpdateClassSession::class)->handle($second, [
        'starts_at' => $at->toIso8601String(),
    ]))->toThrow(DomainException::class);

    // And the row it would have landed on is untouched.
    expect($first->fresh()->starts_at->equalTo($at))->toBeTrue();
});

it('lets a session be edited without reading it as a clash with itself', function (): void {
    $session = app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, CarbonImmutable::now()->addDays(2)->startOfHour()),
        $this->owner,
    );

    $updated = app(UpdateClassSession::class)->handle($session, ['title' => 'العنوان الجديد']);

    expect($updated->title)->toBe('العنوان الجديد');
});

/*
| ⛔ THE CLASH CHECK WAS A READ FOLLOWED BY A WRITE (2026-09-24), and two accepts
| of two students' private requests for one hour both found it free.
|
| ⚠️ A SEQUENTIAL «schedule it twice» TEST IS GREEN AGAINST A BUILD WITH NO CLAIM:
| the second call's `exists()` already sees the first row. The window is between
| the overlap question and the insert, and it is opened single-threaded by
| running the OTHER writer from inside that very query — a `DB::listen` on the
| outer call's `exists` over `class_sessions`. That IS the other worker winning
| in exactly that instant, no threads and no sleeps.
|
| ⚠️ The other writer's row is rolled back WITH the loser, because the window sits
| inside the loser's transaction on one test connection — so what is asserted is
| the refusal and «never two», not that the winner's row survives. Delete the
| claim in `SessionClash` and both calls land: no exception, two rooms.
*/
function scheduleRaceWinnerInsideTheWindow(Closure $winner): void
{
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, $winner): void {
        if ($raced
            || ! str_starts_with(strtolower($query->sql), 'select exists')
            || ! str_contains($query->sql, '"class_sessions"')) {
            return;
        }

        $raced = true;
        $winner();
    });
}

it('refuses the second of two schedules that both found the hour free', function (): void {
    $at = CarbonImmutable::now()->addDays(2)->startOfHour();

    scheduleRaceWinnerInsideTheWindow(fn () => app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, $at),
        $this->owner,
    ));

    expect(fn () => app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, $at->addMinutes(30)),
        $this->owner,
    ))->toThrow(DomainException::class, 'تغيّر جدول المدرّس في هذه اللحظة من جهة أخرى. أعد المحاولة.');

    expect(ClassSession::query()->withoutWorkspaceScope()
        ->where('teacher_profile_id', $this->teacher->getKey())->count())->toBeLessThan(2);
});

it('refuses the second of two moves that both found the hour free', function (): void {
    $at = CarbonImmutable::now()->addDays(2)->startOfHour();

    $first = app(ScheduleClassSession::class)->handle(scheduleData($this->teacher, $at), $this->owner);
    $second = app(ScheduleClassSession::class)->handle(scheduleData($this->teacher, $at->addHours(2)), $this->owner);
    $target = $at->addHours(5);

    // A reschedule approval and a teacher's edit, landing on one hour at once.
    scheduleRaceWinnerInsideTheWindow(fn () => app(UpdateClassSession::class)->handle($first, [
        'starts_at' => $target->toIso8601String(),
    ]));

    expect(fn () => app(UpdateClassSession::class)->handle($second, [
        'starts_at' => $target->toIso8601String(),
    ]))->toThrow(DomainException::class);

    expect(ClassSession::query()->withoutWorkspaceScope()
        ->where('teacher_profile_id', $this->teacher->getKey())
        ->where('starts_at', $target)
        ->count())->toBeLessThan(2);
});

/*
| ⛔ THE CLASH IS ABOUT A PERSON, AND THE SCOPE MADE IT ABOUT A WORKSPACE. A
| teacher can be scheduled from more than one workspace (`SchedulableTeachers`),
| and the scoped read ANDed the writer's own workspace onto the question — so the
| other workspace's Saturday was invisible to the one being written on top of it.
| One workspace in the fixture can never see this.
*/
it('sees the same teacher\'s session in another workspace as a clash', function (): void {
    [$elsewhere] = $this->createWorkspaceWithOwner();
    $at = CarbonImmutable::now()->addDays(2)->startOfHour();

    ClassSession::factory()->create([
        'workspace_id' => $elsewhere->getKey(),
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => Course::factory()->create(['workspace_id' => $elsewhere->getKey()])->getKey(),
        'starts_at' => $at,
        'ends_at' => $at->addHour(),
        'duration_minutes' => 60,
    ]);

    // The writer stands in THIS workspace; the other session is not in it.
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    expect(fn () => app(ScheduleClassSession::class)->handle(
        scheduleData($this->teacher, $at->addMinutes(30)),
        $this->owner,
    ))->toThrow(DomainException::class, 'لديك حصة أخرى في هذا الوقت.');
});
