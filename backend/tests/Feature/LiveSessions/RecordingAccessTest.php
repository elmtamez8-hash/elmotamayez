<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\LessonAccess;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\SessionContentAccess;
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
|
| ⛔ AND ٠٣٥ NARROWED «THE PEOPLE WHO BOOKED» TO «THE PEOPLE WHO RECEIVED IT»,
| ON 2026-09-13. Holding a booking and receiving the hour were one fact until the
| day the absentee stopped being charged for it. Now the seat is what lets you
| BUY the hour and `attendances.credit_verdict_at` is what says you already own
| it: whoever was charged — the attender, the silent no-show, the late canceller
| — watches, and whoever gave notice keeps their credit and the recording stays
| shut until they spend one on purpose.
|
| ⚠️ SO `learner()` STAMPS THE VERDICT, and that is the whole edit to this file.
| Every case below asks the same question it always asked; what changed is that
| a fixture holding only a booking is now a student the product cannot produce —
| a seat is not a verdict, and `CloseClassSession` never leaves one without the
| other on a delivered session.
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
        /*
        | ⚠️ DELIVERED AND JUDGED, AND ٠٢٦ IS WHY IT HAD TO BE SAID OUT LOUD.
        |
        | This fixture hangs a PUBLISHED RECORDING off a session — and a
        | recording exists only because the hour ran. Left with `delivered_at`
        | null the pair is a state production never reaches, and since ٠٢٦ it
        | means «this hour was never given»: the gate answers
        | `no_session_content` and the row is removed, so the `no_seat` this file
        | is about stopped existing.
        |
        | `attended_seats` is the second half. `openableSessionIds()`'s first arm
        | is «delivered but not yet judged ⇒ open to EVERYONE» — the ٠٣٥ deploy
        | window — and it is not per-student, so leaving it null hands the
        | recording to the unseated learner this file exists to refuse.
        |
        | ⚠️ AND `status` IS LEFT ALONE, DELIBERATELY. `BookSeat` refuses
        | anything but a scheduled session, and five cases here book a seat — so
        | «completed» would refuse the fixture itself with «لم تعد متاحة للحجز».
        | `starts_at` stays in the future for the same reason: it is what puts
        | the cancellation cases comfortably inside the 24-hour window. Neither
        | is read on the content path, which asks `delivered_at` alone.
        */
        'delivered_at' => CarbonImmutable::now()->subDay(),
        'attended_seats' => 0,
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

/**
 * A student enrolled in the course, optionally holding a seat — and, since ٠٣٥,
 * optionally having RECEIVED the hour.
 *
 * @param  bool|null  $received  null means «the same as the seat», which is what a
 *                               delivered session produces for somebody who sat
 *                               through it. Pass it explicitly whenever the case
 *                               is about the two disagreeing.
 */
function learner(bool $withSeat, ?bool $received = null): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);
    // Prepaid is the default, so a seat has to be paid for before it can be
    // taken. The subject of this file is not money; the funding is fixture.
    fundBooking($test->workspace, $student, $test->course);

    if ($withSeat) {
        app(BookSeat::class)->handle($test->session->refresh(), $student);
    }

    if ($received ?? $withSeat) {
        attendanceRow($test->workspace, $test->session, $student, AttendanceStatus::Present);
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
    // Released before the deadline, so nothing was ever charged — no verdict.
    $student = learner(withSeat: true, received: false);

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

it('reads the entitlement through the shared contract, not the model', function (): void {
    $student = learner(withSeat: true);

    /*
    | Media depends on an INTERFACE and never on another module's models
    | (Constitution III). ٠٣٥ changed WHICH interface — the question moved from
    | «do they hold a seat» to «did they receive the hour» — so this asserts the
    | contract the grant actually resolves today. If the binding disappears, this
    | is what says so.
    */
    expect(app(SessionContentAccess::class)
        ->mayOpenSessionContent($student, (int) $this->session->getKey()))->toBeTrue();
});

/*
| ⚠️ AND THE LESSON PAGE MUST AGREE WITH THE GRANT.
|
| Everything above measures `mayWatch`, the door the FILE is served through. The
| student reaches a recording through `/learn/lessons/{uuid}`, whose door is
| `Enrollment::accessTo()` — and that one knew only about enrolment and the
| course's sequence. So on 2026-08-18 a student who had booked and paid for a
| live session opened the lesson it produced and read «أكمِل … أولاً» about
| unrelated coursework, while the grant endpoint would happily have played it.
|
| The prerequisite query had long refused to let a recording STAND in front of
| anything; this is the mirror image, and it was missing. Both directions are
| asserted, because allowing every recording through would hand the hour to the
| whole cohort — which is the defect FR-030 exists to prevent.
*/
it('opens the lesson page for a seat holder even in a sequential course', function (): void {
    $this->course->update(['is_sequential' => true]);

    // Something unfinished in front of it, so the sequence would refuse.
    Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'section_id' => $this->recording->section_id,
        'chapter_id' => $this->recording->chapter_id,
        'order' => $this->recording->order - 1,
        'type' => 'video',
        'status' => 'published',
    ]);

    $seated = learner(withSeat: true);
    $unseated = learner(withSeat: false);

    $enrollmentOf = fn (User $student) => Enrollment::query()
        ->where('course_id', $this->course->getKey())
        ->where('student_user_id', $student->getKey())
        ->firstOrFail();

    expect($enrollmentOf($seated)->accessTo($this->recording)->allowed)->toBeTrue();

    $refusal = $enrollmentOf($unseated)->accessTo($this->recording);

    // Refused for the RIGHT reason: there is nothing to go and finish.
    expect($refusal->allowed)->toBeFalse()
        ->and($refusal->code)->toBe(LessonAccess::NO_SEAT);
});
