<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\GenerateSessionsFromAvailability;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
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
    return new ScheduleSessionData(
        teacherProfileId: (int) $teacher->getKey(),
        courseId: (int) Course::query()->where('workspace_id', $teacher->workspace_id)->value('id'),
        title: 'حصة رياضيات',
        type: ClassSessionType::Group,
        startsAt: $startsAt,
        durationMinutes: $minutes,
        seatsTotal: 8,
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
