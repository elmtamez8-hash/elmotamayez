<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;

/*
| Owner decision, 2026-09-24: a guardian is told when their child is enrolled.
| Measured on production before this: `enrollment_created` reached the student
| alone.
|
| Driven through `EnrollStudent`, the one writer every door shares, so what is
| proven is the WIRING — the queued listener, the `subject:` it passes, and the
| resolver finding the child's guardians from it — not a hand-built request.
| No `Queue::fake()`: the listener is queued, and a fake would make every
| assertion below a claim about an empty table.
|
| The student is built with `last_workspace_id` NULL, the self-registered shape.
*/

/** @return array{course: Course, student: User} */
function enrolmentNoticeFixture(): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner();
    $student = User::factory()->create();

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
        'title' => 'الرياضيات',
    ]));

    return ['course' => $course, 'student' => $student];
}

it('tells a guardian on the payments consent, with a link to the child on their own dashboard', function (): void {
    ['course' => $course, 'student' => $student] = enrolmentNoticeFixture();
    $guardian = guardianOf($student, [GuardianPermission::Payments]);

    app(EnrollStudent::class)->handle($course, $student);

    $row = assertNotifiedOnce($guardian, NotificationType::EnrollmentCreated);

    // The link the guardian can open: the child's own curriculum page would
    // answer a guardian with nothing, since they are nobody's student.
    expect($row->action_url)->toBe('/dashboard?student='.$student->uuid)
        // Third person, so a parent is not addressed as the child.
        ->and((string) $row->body)->toContain($student->name)
        ->and((string) $row->body)->toContain('الرياضيات');

    $own = assertNotifiedOnce($student, NotificationType::EnrollmentCreated);

    expect($own->action_url)->toBe('/enrollments/'.$course->uuid);
});

it('does not tell a guardian who holds other consents but not payments', function (): void {
    ['course' => $course, 'student' => $student] = enrolmentNoticeFixture();
    $scheduleOnly = guardianOf($student, [
        GuardianPermission::Schedule,
        GuardianPermission::Attendance,
        GuardianPermission::Results,
    ]);

    app(EnrollStudent::class)->handle($course, $student);

    assertNotifiedOnce($student, NotificationType::EnrollmentCreated);

    expect(wasNotified($scheduleOnly, NotificationType::EnrollmentCreated))->toBeFalse();
});

it('does not tell the guardian of a different student', function (): void {
    ['course' => $course, 'student' => $student] = enrolmentNoticeFixture();
    $someoneElse = User::factory()->create();
    $unrelated = guardianOf($someoneElse, [GuardianPermission::Payments]);

    app(EnrollStudent::class)->handle($course, $student);

    assertNotifiedOnce($student, NotificationType::EnrollmentCreated);

    expect(wasNotified($unrelated, NotificationType::EnrollmentCreated))->toBeFalse();
});

it('rewords the shipped enrolment template on a live database, and leaves an operator edit alone', function (): void {
    $migration = require base_path('app/Modules/Notifications/Database/Migrations/2026_09_24_000700_reword_enrollment_created_for_guardians.php');
    $constant = static fn (string $name): string => (string) (new ReflectionClassConstant($migration, $name))->getValue();

    $row = MessageTemplate::query()
        ->where('type', NotificationType::EnrollmentCreated->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->firstOrFail();

    // The shape an existing database carries: the second-person wording.
    $row->forceFill(['title' => $constant('SHIPPED_TITLE'), 'body' => $constant('SHIPPED_BODY')])->save();
    $migration->up();

    expect($row->fresh()?->title)->toBe($constant('REWORDED_TITLE'))
        ->and($row->fresh()?->body)->toBe($constant('REWORDED_BODY'));

    // An operator's own wording is theirs.
    $row->forceFill(['body' => 'OPERATOR_WORDING {{ name }} {{ course_title }}'])->save();
    $migration->up();

    expect($row->fresh()?->body)->toBe('OPERATOR_WORDING {{ name }} {{ course_title }}');
});
