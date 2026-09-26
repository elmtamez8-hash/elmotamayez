<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Actions\DeleteFreezePeriod;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/*
| A WORKSPACE freeze suspends every group lesson in it and releases every seat.
| Lifting it brought the lessons back and, for a student who pays by credit,
| nothing else: the seat stayed released and nobody said the hour was bookable
| again (owner decision 2026-09-26). They are told now — and NOT rebooked: the
| student decides whether to spend a credit on the hour.
*/

it('tells a credit-paying student their group lessons are back, and leaves the seat for them to take', function (): void {
    // Midday UTC: the freeze's days are the platform's, and the two calendars
    // agree on the date here.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $course = courseWithRate((int) $workspace->getKey());
    $profile = TeacherProfile::query()->withoutWorkspaceScope()->whereKey($course->teacher_profile_id)->firstOrFail();

    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $this->createEnrollment($workspace, $course, $student);
    fundBooking($workspace, $student, $course);
    $this->setCurrentWorkspace($workspace, $owner);

    $startsAt = CarbonImmutable::now()->addDays(10);
    $lesson = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): ClassSession => ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'teacher_profile_id' => $profile->getKey(),
        'course_id' => $course->getKey(),
        'type' => ClassSessionType::Group,
        'seats_total' => 4,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'duration_minutes' => 60,
    ]));

    app(BookSeat::class)->handle($lesson, $student);

    $period = app(CreateFreezePeriod::class)->handle(
        $owner,
        CarbonImmutable::now()->addDays(9),
        CarbonImmutable::now()->addDays(11),
    )['period'];

    expect($lesson->refresh()->status)->toBe(ClassSessionStatus::Suspended);

    app(DeleteFreezePeriod::class)->handle($period);

    $seat = SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $lesson->getKey())
        ->where('student_user_id', $student->getKey())
        ->firstOrFail();

    expect($lesson->refresh()->status)->toBe(ClassSessionStatus::Scheduled)
        ->and($seat->status)->toBe(BookingStatus::Released);

    $notice = assertNotifiedOnce($student, NotificationType::SessionSeatReopened);

    expect((string) $notice->body)->toContain((string) $lesson->title);

    // And the notice is true: the student can take the seat back.
    expect(app(BookSeat::class)->handle($lesson->refresh(), $student)->status)->toBe(BookingStatus::Booked);
});
