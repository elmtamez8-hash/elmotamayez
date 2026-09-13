<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Support\LessonAccess;
use App\Modules\Learning\Support\LessonGate;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Modules\Payments\Actions\UnlockSessionContent;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T025 — THE SIX DOORS, EACH ASKED ON ITS OWN.
|
| ⛔ AND THE DISCRIMINATING CASES ARE THE POSITIVE ONES, NOT THE NEGATIVE ONES.
| That is the whole design of this file and it is counter-intuitive.
|
| A negative case built on «cancelled in the window» proves nothing: that seat is
| ALREADY refused at every door today, because `BookingStatus::occupiesSeat()` is
| `Booked` alone and every gate in the product reads a seat. Write the file that
| way and it passes in full against a build with no gate in it whatsoever.
|
| The three that bite are:
|
|  · the student who CANCELLED IN THE WINDOW and then paid ⇒ allowed at all six.
|    Today they hold no seat, so today every door refuses them.
|  · the student who was NEVER IN THE ROOM and paid ⇒ allowed. Same reason.
|  · the EXCUSED student who has not paid ⇒ refused at all six. Today they hold
|    a seat, so today every door lets them straight in.
|
| ⚠️ AND THE RECORDING IS ASKED THROUGH THE BULK FORM TOO. `IssuePlaybackGrant`
| carries two hand-written spellings of one right — `mayWatch()` and
| `mayWatchMany()` — and the second is the door the curriculum screen goes
| through. Changing one alone is the ٠١٨ defect with the roles reversed.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);

    // The hour's own material: a recording, an exam and a piece of homework, all
    // three carrying the session.
    $this->recording = Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'class_session_id' => $this->session->getKey(),
        'type' => LessonType::Video,
        'status' => ContentStatus::Published,
    ]);

    $this->exam = Exam::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'status' => 'published',
    ]);

    $this->examLesson = Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'class_session_id' => $this->session->getKey(),
        'type' => LessonType::Exam,
        'reference_id' => $this->exam->getKey(),
        'status' => ContentStatus::Published,
    ]);

    $this->assignment = Assignment::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'class_session_id' => $this->session->getKey(),
        'status' => 'published',
        'submission_type' => Assignment::TYPE_TEXT,
    ]);
});

/** Enrolled, funded, and holding a seat on the hour. */
function contentGateStudent(): User
{
    $test = test();

    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);
    fundBooking($test->workspace, $student, $test->course);

    app(BookSeat::class)->handle($test->session->refresh(), $student);

    return $student;
}

/** Whether each of the six doors opens for this person. */
function contentDoorsFor(User $student): array
{
    $test = test();

    $enrollment = $test->course->enrollments()->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())->firstOrFail();

    $single = LessonGate::for($enrollment, $test->recording);

    $bulk = LessonGate::forTree($enrollment, [$test->recording, $test->examLesson]);

    $grant = app(IssuePlaybackGrant::class);

    $examAllowed = true;

    try {
        app(StartAttempt::class)->handle($test->exam->refresh(), $student);
    } catch (DomainException) {
        $examAllowed = false;
    }

    $assignmentAllowed = true;

    try {
        app(SubmitAssignment::class)->handle($test->assignment->refresh(), $student, 'إجابتي');
    } catch (DomainException $refusal) {
        // Only the LOCK counts as a refusal here. A deadline or a duplicate is a
        // different sentence and folding them together would make this door
        // report «locked» for reasons that have nothing to do with ٠٣٥.
        $assignmentAllowed = ! str_contains($refusal->getMessage(), 'مقفول');
    }

    return [
        'lesson' => $single->allowed,
        'lesson_bulk' => $bulk[(int) $test->recording->getKey()]->allowed,
        'playback' => $grant->mayWatch($test->recording->refresh(), $student),
        'playback_bulk' => $grant->mayWatchMany(
            Lesson::query()->withoutWorkspaceScope()
                ->whereKey([$test->recording->getKey(), $test->examLesson->getKey()])->get(),
            $student,
        )[(int) $test->recording->getKey()],
        'exam' => $examAllowed,
        'assignment' => $assignmentAllowed,
    ];
}

it('opens all six for whoever sat through the hour', function (): void {
    $student = contentGateStudent();

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
    deliverBillableSession($this->session->refresh(), $this->owner, [$student]);

    expect(contentDoorsFor($student))->toBe([
        'lesson' => true,
        'lesson_bulk' => true,
        'playback' => true,
        'playback_bulk' => true,
        'exam' => true,
        'assignment' => true,
    ]);
});

it('shuts all six on the excused student who has not paid', function (): void {
    /*
    | ⛔ THE CASE THAT FAILS ON TODAY'S BUILD. This student HOLDS A SEAT — they
    | were excused, not released — so every door in the product admits them
    | today. They gave notice, kept their credit, and FR-008د says the hour stays
    | shut until they spend one on purpose.
    */
    $student = contentGateStudent();

    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $student->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()]);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
    deliverBillableSession($this->session->refresh(), $this->owner, []);

    expect(contentDoorsFor($student))->toBe([
        'lesson' => false,
        'lesson_bulk' => false,
        'playback' => false,
        'playback_bulk' => false,
        'exam' => false,
        'assignment' => false,
    ]);
});

it('opens all six for the student who cancelled in time and then paid', function (): void {
    /*
    | ⛔ AND THIS IS THE MIRROR. Cancelling inside the window RELEASES the seat,
    | so today this person is refused at every door with no way in at all — the
    | «lock with no exit» FR-013 forbids. After ٠٣٥ they spend one credit and the
    | hour is theirs.
    */
    $student = contentGateStudent();

    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $student->getKey())
        ->update(['status' => 'cancelled_in_window']);

    $this->session->refresh()->forceFill(['billable_seats' => 0])->save();
    deliverBillableSession($this->session->refresh(), $this->owner, []);

    expect(contentDoorsFor($student)['lesson'])->toBeFalse();

    app(UnlockSessionContent::class)->handle($student, $this->session->refresh());

    expect(contentDoorsFor($student))->toBe([
        'lesson' => true,
        'lesson_bulk' => true,
        'playback' => true,
        'playback_bulk' => true,
        'exam' => true,
        'assignment' => true,
    ]);
});

it('opens all six for the enrolled student who never held a seat and then paid', function (): void {
    // Never booked at all. Enrolment alone entitles them to BUY the hour, which
    // is the door `SessionContentController::entitled()` opens and the one an
    // implementation reading only `session_bookings` cannot.
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    fundBooking($this->workspace, $student, $this->course);

    $this->session->refresh()->forceFill(['billable_seats' => 0])->save();
    deliverBillableSession($this->session->refresh(), $this->owner, []);

    expect(contentDoorsFor($student)['playback'])->toBeFalse();

    app(UnlockSessionContent::class)->handle($student, $this->session->refresh());

    expect(contentDoorsFor($student))->toBe([
        'lesson' => true,
        'lesson_bulk' => true,
        'playback' => true,
        'playback_bulk' => true,
        'exam' => true,
        'assignment' => true,
    ]);
});

it('never lets the sequence stand in front of a locked hour', function (): void {
    /*
    | ⚠️ THE REFUSAL IS «LOCKED», NEVER «FINISH SOMETHING ELSE FIRST». They are
    | different sentences with different exits: one is bought with a credit and
    | the other cannot be satisfied at all, because a recording is entitled by
    | the hour and not by the course order. Answering the second about the first
    | is the ٠١٨ defect — «أكمِل … أولاً» about a lesson the student had paid for.
    */
    $student = contentGateStudent();

    $this->course->forceFill(['is_sequential' => true])->save();

    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $student->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()]);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
    deliverBillableSession($this->session->refresh(), $this->owner, []);

    $enrollment = $this->course->enrollments()->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())->firstOrFail();

    expect(LessonGate::for($enrollment, $this->recording)->code)
        ->toBe(LessonAccess::NO_SEAT);
});
