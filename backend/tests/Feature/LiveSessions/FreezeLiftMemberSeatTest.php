<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Actions\DeleteFreezePeriod;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Lifting a freeze on ONE student, for a student who is NOT a subscriber.
|
| A freeze on one student takes that student's seat out of a group lesson and
| leaves the lesson running for everybody else. PR #230 gave a group SUBSCRIBER
| their seat back when the freeze was lifted; a member who pays by credit got
| nothing back and was told nothing. Lifting now revives the seat through the
| same atomic path the automation uses — or, when the room filled up meanwhile,
| leaves it released and says so.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->profile = TeacherProfile::query()->withoutWorkspaceScope()
        ->whereKey($this->course->teacher_profile_id)->firstOrFail();

    $this->student = memberSeatStudent();

    $startsAt = CarbonImmutable::now()->addDays(10);
    $this->lesson = app(WorkspaceContext::class)->forWorkspace($this->workspace, fn (): ClassSession => ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $this->profile->getKey(),
        'course_id' => $this->course->getKey(),
        'type' => ClassSessionType::Group,
        'seats_total' => 2,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'duration_minutes' => 60,
    ]));

    app(BookSeat::class)->handle($this->lesson, $this->student);

    $this->period = app(CreateFreezePeriod::class)->handle(
        $this->owner,
        CarbonImmutable::now()->addDays(9),
        CarbonImmutable::now()->addDays(11),
        $this->student,
    )['period'];

    expect($this->lesson->refresh()->status)->toBe(ClassSessionStatus::Scheduled)
        ->and(memberSeat($this->lesson, $this->student)->status)->toBe(BookingStatus::Released)
        ->and($this->lesson->seats_taken)->toBe(0);
});

function memberSeatStudent(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    fundBooking($test->workspace, $student, $test->course);
    $test->setCurrentWorkspace($test->workspace, $test->owner);

    return $student;
}

function memberSeat(ClassSession $session, User $student): SessionBooking
{
    return SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $student->getKey())
        ->firstOrFail();
}

it('gives a credit-paying member their group seat back when the freeze on them is lifted', function (): void {
    app(DeleteFreezePeriod::class)->handle($this->period);

    $seat = memberSeat($this->lesson, $this->student);

    expect($seat->status)->toBe(BookingStatus::Booked)
        ->and($seat->is_billable)->toBeTrue()
        ->and($this->lesson->refresh()->seats_taken)->toBe(1)
        ->and(DB::table('credit_holds')
            ->where('class_session_id', $this->lesson->getKey())
            ->whereNull('outcome')
            ->count())->toBe(1)
        ->and(wasNotified($this->student, NotificationType::SubscriptionSeatUnavailable))->toBeFalse();
});

it('leaves the seat released and tells the student and the teacher when the room filled up meanwhile', function (): void {
    app(BookSeat::class)->handle($this->lesson, memberSeatStudent());
    app(BookSeat::class)->handle($this->lesson, memberSeatStudent());

    expect($this->lesson->refresh()->seats_taken)->toBe(2);

    app(DeleteFreezePeriod::class)->handle($this->period);

    expect(memberSeat($this->lesson, $this->student)->status)->toBe(BookingStatus::Released)
        ->and($this->lesson->refresh()->seats_taken)->toBe(2);

    assertNotifiedOnce($this->student, NotificationType::SubscriptionSeatUnavailable);
    assertNotifiedOnce($this->owner, NotificationType::SubscriptionSeatUnavailable);
});

it('does not give the seat back while another freeze still covers the student', function (): void {
    app(WorkspaceContext::class)->forWorkspace($this->workspace, fn (): FreezePeriod => FreezePeriod::query()->create([
        'student_user_id' => null,
        'starts_on' => CarbonImmutable::now()->addDays(10)->toDateString(),
        'ends_on' => CarbonImmutable::now()->addDays(10)->toDateString(),
        'created_by' => $this->owner->getKey(),
    ]));

    app(DeleteFreezePeriod::class)->handle($this->period);

    expect(memberSeat($this->lesson, $this->student)->status)->toBe(BookingStatus::Released)
        ->and(wasNotified($this->student, NotificationType::SubscriptionSeatUnavailable))->toBeFalse();
});
