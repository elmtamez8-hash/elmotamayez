<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OverrideAttendance;
use App\Modules\LiveSessions\Actions\SendSessionReport;
use App\Modules\LiveSessions\Actions\SubmitSessionFeedback;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-009 — a finished session reaches the guardian's enabled channel within the
| announced delay.
|
| Queue::fake() throughout, and not only to assert dispatches: a ->delay() on the
| `sync` connection runs IMMEDIATELY (a delay is a queue-driver feature and sync
| has no queue), so without it the report job would fire inside CloseClassSession
| and every timing assertion here would be about nothing.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);
});

/** An enrolled student holding a seat in the session. */
function reportedLearner(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);

    app(BookSeat::class)->handle($test->session->refresh(), $student);

    return $student;
}

/** Close the session and run the delayed report job by hand. */
function closeAndReport(): void
{
    app(CloseClassSession::class)->handle(test()->session->refresh());

    app(SendSessionReportsJob::class, ['classSessionId' => (int) test()->session->getKey()])
        ->handle(app(WorkspaceContext::class), app(SendSessionReport::class));
}

it('dispatches the report after the announced delay, not on the spot', function (): void {
    reportedLearner();

    app(CloseClassSession::class)->handle($this->session->refresh());

    Queue::assertPushed(
        SendSessionReportsJob::class,
        // A report with no delay would beat the teacher to their own register.
        fn (SendSessionReportsJob $job): bool => $job->delay !== null,
    );
});

// FR-033 — the guardian is the audience; the student is told too because it is
// their own record.
it('reaches the guardian and the student', function (): void {
    $student = reportedLearner();
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $guardian->getKey(),
    ]);

    closeAndReport();

    expect(Notification::query()->where('type', NotificationType::SessionReport->value)->count())->toBe(2)
        ->and(Notification::query()->forRecipient($guardian)->count())->toBe(1)
        ->and(Notification::query()->forRecipient($student)->count())->toBe(1);
});

// FR-035 — the remark is optional and the report never waits for one.
it('sends on attendance alone when the teacher wrote nothing', function (): void {
    $student = reportedLearner();

    closeAndReport();

    $notification = Notification::query()->forRecipient($student)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->body_ar)->toContain('غائب')
        ->and($notification->body_ar)->toContain('لا ملاحظات إضافية.');
});

it('carries the teacher remark when there is one', function (): void {
    $student = reportedLearner();

    app(SubmitSessionFeedback::class)->handle($this->session->refresh(), $this->owner, [
        ['student_uuid' => $student->uuid, 'rating' => 4, 'note' => 'شارك بفاعلية.'],
    ]);

    closeAndReport();

    expect(Notification::query()->forRecipient($student)->first()->body_ar)
        ->toContain('شارك بفاعلية.');
});

// Two guardians both hear about it, and the row is reported once — a rerun of
// the job must not tell either of them about the same hour twice.
it('tells two guardians once each and does not repeat itself', function (): void {
    $student = reportedLearner();

    $first = User::factory()->create();
    $second = User::factory()->create();

    foreach ([$first, $second] as $guardian) {
        ParentStudentRelation::factory()->create([
            'student_user_id' => $student->getKey(),
            'guardian_user_id' => $guardian->getKey(),
        ]);
    }

    closeAndReport();

    // Run it a second time, the way a retried job would.
    app(SendSessionReportsJob::class, ['classSessionId' => (int) $this->session->getKey()])
        ->handle(app(WorkspaceContext::class), app(SendSessionReport::class));

    expect(Notification::query()->forRecipient($first)->count())->toBe(1)
        ->and(Notification::query()->forRecipient($second)->count())->toBe(1)
        ->and(Notification::query()->forRecipient($student)->count())->toBe(1);
});

// FR-038 — a disabled preference silences the channel and nothing else. The
// record stays inside the platform, because the feed is where a notification
// lives and "do not push it at me" is not "pretend it never happened".
it('honours a guardian who switched the channel off', function (): void {
    $student = reportedLearner();
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $guardian->getKey(),
    ]);

    NotificationPreference::query()->create([
        'user_id' => $guardian->getKey(),
        'type' => NotificationType::SessionReport->value,
        'channels' => [],
    ]);

    closeAndReport();

    $notification = Notification::query()->forRecipient($guardian)->first();

    expect($notification)->not->toBeNull()
        ->and(NotificationDelivery::query()->where('notification_id', $notification->getKey())->count())->toBe(0);
});

// FR-037 — the guardian who received a wrong report gets the right one.
it('sends a correction when attendance is edited after the report went out', function (): void {
    $student = reportedLearner();

    closeAndReport();

    $attendance = Attendance::query()->where('student_user_id', $student->getKey())->firstOrFail();

    app(OverrideAttendance::class)->handle(
        $attendance,
        AttendanceStatus::Present,
        $this->owner,
        'حضر من جهاز زميله.',
    );

    $reports = Notification::query()->forRecipient($student)->orderBy('id')->get();

    expect($reports)->toHaveCount(2)
        ->and($reports->last()->body_ar)->toContain('تصحيح لتقرير سابق.')
        ->and($reports->last()->body_ar)->toContain('حاضر');
});

// An edit before the delay elapses is not a correction: nobody has been told
// anything yet, and a "correction" to a message never received explains nothing.
it('does not send a correction for a row nobody has heard about', function (): void {
    $student = reportedLearner();

    app(CloseClassSession::class)->handle($this->session->refresh());

    $attendance = Attendance::query()->where('student_user_id', $student->getKey())->firstOrFail();

    app(OverrideAttendance::class)->handle(
        $attendance,
        AttendanceStatus::Present,
        $this->owner,
        'حضر متأخراً.',
    );

    expect(Notification::query()->where('type', NotificationType::SessionReport->value)->count())->toBe(0);
});

// A notification with no template is dropped silently by design (CLAUDE.md), so
// the stamp must not claim otherwise: an unconditional write here would mark the
// row reported and the retry guard would make sure nobody ever tried again.
it('leaves the row unreported when the template is missing', function (): void {
    $student = reportedLearner();

    MessageTemplate::query()->where('type', NotificationType::SessionReport->value)->delete();

    closeAndReport();

    $attendance = Attendance::query()->where('student_user_id', $student->getKey())->firstOrFail();

    expect(Notification::query()->count())->toBe(0)
        ->and($attendance->report_sent_at)->toBeNull();
});
