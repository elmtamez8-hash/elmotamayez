<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\SessionAttendanceDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| SC-008 — a session recording is watchable by the people who booked the
| session, and by nobody else.
|
| The distinction this proves is the whole reason for the third entitlement
| route: enrolment in the COURSE is not enough. Publishing the recording as an
| ordinary lesson without it would hand the hour to every student on the
| register, including the ones who were not in the room and were never charged
| for it.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $section = Section::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);
    $chapter = Chapter::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'section_id' => $section->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        // Comfortably beyond the 24-hour cancellation window, so a cancellation
        // made "in time" in one test really is inside it rather than sitting on
        // the boundary.
        'starts_at' => CarbonImmutable::now()->addDays(5),
        'ends_at' => CarbonImmutable::now()->addDays(5)->addHour(),
    ]);

    // The lesson a published recording produces.
    $this->recording = Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'section_id' => $section->getKey(),
        'chapter_id' => $chapter->getKey(),
        'class_session_id' => $this->session->getKey(),
        'type' => 'video',
        'is_free' => false,
        'is_preview' => false,
    ]);
});

/** A student enrolled in the course, optionally holding a seat. */
function learner(bool $withSeat): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);

    if ($withSeat) {
        app(BookSeat::class)->handle($test->session->refresh(), $student);
    }

    return $student;
}

it('lets someone who booked a seat watch the recording', function (): void {
    $student = learner(withSeat: true);

    expect(app(IssuePlaybackGrant::class)->mayWatch($this->recording, $student))->toBeTrue();
});

// The headline. Enrolled in the course, was not in the room, was not charged.
it('refuses an enrolled student who booked no seat', function (): void {
    $student = learner(withSeat: false);

    expect(app(IssuePlaybackGrant::class)->mayWatch($this->recording, $student))->toBeFalse();
});

// FR-010 — the seat was paid for, so withholding the recording would be taking
// payment and giving nothing.
it('keeps access after a late cancellation', function (): void {
    $student = learner(withSeat: true);

    // Past the deadline, so the seat stays billable.
    $this->session->update([
        'starts_at' => CarbonImmutable::now()->addMinutes(30),
        'ends_at' => CarbonImmutable::now()->addMinutes(90),
    ]);

    $booking = $this->session->bookings()->where('student_user_id', $student->getKey())->first();
    app(CancelBooking::class)->handle($booking);

    expect(app(IssuePlaybackGrant::class)->mayWatch($this->recording, $student))->toBeTrue();
});

it('drops access when a seat was released in time', function (): void {
    $student = learner(withSeat: true);

    $booking = $this->session->bookings()->where('student_user_id', $student->getKey())->first();
    app(CancelBooking::class)->handle($booking);

    expect(app(IssuePlaybackGrant::class)->mayWatch($this->recording, $student))->toBeFalse();
});

it('still lets the teacher who produced it watch', function (): void {
    expect(app(IssuePlaybackGrant::class)->mayWatch($this->recording, $this->owner))->toBeTrue();
});

// The bulk path must agree with the single one, or a course listing would show
// a play button that the grant endpoint then refuses.
it('answers the same in the bulk path', function (): void {
    $seated = learner(withSeat: true);
    $unseated = learner(withSeat: false);

    $action = app(IssuePlaybackGrant::class);

    expect($action->mayWatchMany([$this->recording], $seated))
        ->toBe([(int) $this->recording->getKey() => true])
        ->and($action->mayWatchMany([$this->recording], $unseated))
        ->toBe([(int) $this->recording->getKey() => false]);
});

it('reads the seat through the shared contract, not the model', function (): void {
    $student = learner(withSeat: true);

    // Media depends on this interface and never on LiveSessions' models
    // (Constitution III). If the binding disappears, this is what says so.
    expect(app(SessionAttendanceDirectory::class)->hasBookingForLesson($student, (int) $this->recording->getKey()))
        ->toBeTrue();
});
