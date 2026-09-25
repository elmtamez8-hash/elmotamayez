<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| Owner decision 2026-09-25 — a student books ONLY the sessions of a course they
| are enrolled in, and, when the session belongs to a group, only if they are a
| current member of THAT group.
|
| Until then the booking door asked «are you studying with this teacher at all»:
| enrolled in maths, a student could book the physics session by its uuid, and a
| Saturday-group member could take a seat in the Sunday group's lesson.
|
| ⚠️ EVERY CASE RUNS FOR BOTH STUDENTS THIS REPOSITORY HAS: the self-registered
| one whose context is NULL, and the one a teacher once added to ANOTHER
| workspace, whose stamped `last_workspace_id` turns the scope on against this
| teacher's rows. Stamped with `forceFill` — `last_workspace_id` is guarded, and
| `create([...])` would drop it in silence and rebuild the first student.
*/

dataset('students', [
    'null context' => [false],
    'stamped elsewhere' => [true],
]);

beforeEach(function (): void {
    [$this->workspace, $owner] = $this->createWorkspaceWithOwner();
    [$this->elsewhere] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $owner);

    $this->teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $owner->getKey(),
    ]);

    $this->maths = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $this->physics = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $this->saturday = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->maths->getKey(),
        'name' => 'مجموعة السبت',
    ]);

    $this->sunday = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->maths->getKey(),
        'name' => 'مجموعة الأحد',
    ]);

    $this->student = User::factory()->create();
    $this->createEnrollment($this->workspace, $this->maths, $this->student);
    fundBooking($this->workspace, $this->student, $this->maths);
    fundBooking($this->workspace, $this->student, $this->physics);

    CohortMembership::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->saturday->getKey(),
        'course_id' => $this->maths->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);
});

function scopeSession(array $overrides = []): ClassSession
{
    return ClassSession::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'teacher_profile_id' => test()->teacher->getKey(),
        'course_id' => test()->maths->getKey(),
        'cohort_id' => null,
        'type' => ClassSessionType::Group,
        'starts_at' => CarbonImmutable::now()->addDays(2),
        'ends_at' => CarbonImmutable::now()->addDays(2)->addHour(),
        'duration_minutes' => 60,
        'seats_total' => 5,
        'seats_taken' => 0,
        ...$overrides,
    ]);
}

function bookAsStudent(ClassSession $session, bool $stamped): TestResponse
{
    $student = test()->student;

    if ($stamped) {
        $student->forceFill(['last_workspace_id' => test()->elsewhere->getKey()])->save();
        expect($student->refresh()->last_workspace_id)->toBe(test()->elsewhere->getKey());
    } else {
        expect($student->refresh()->last_workspace_id)->toBeNull();
    }

    // A fresh request resolves the context from scratch — never a pinned null.
    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs($student);

    return test()->postJson("/api/v1/class-sessions/{$session->uuid}/book");
}

function seatsHeld(ClassSession $session): int
{
    return SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->count();
}

it('refuses a session of another group in the same course', function (bool $stamped): void {
    $session = scopeSession(['cohort_id' => $this->sunday->getKey()]);

    bookAsStudent($session, $stamped)
        ->assertStatus(409)
        ->assertJsonPath('message', 'هذه الحصة لمجموعة لست عضواً فيها.');

    expect(seatsHeld($session))->toBe(0)
        ->and((int) $session->refresh()->seats_taken)->toBe(0);
})->with('students');

it('refuses a session of a course the student is not enrolled in, even with the same teacher', function (bool $stamped): void {
    $session = scopeSession(['course_id' => $this->physics->getKey()]);

    bookAsStudent($session, $stamped)
        ->assertStatus(409)
        ->assertJsonPath('message', 'هذه الحصة تابعة لكورس لست مسجّلاً فيه.');

    expect(seatsHeld($session))->toBe(0);
})->with('students');

it('books a session of the student\'s own group', function (bool $stamped): void {
    $session = scopeSession(['cohort_id' => $this->saturday->getKey()]);

    bookAsStudent($session, $stamped)->assertCreated();

    expect(seatsHeld($session))->toBe(1);
})->with('students');

it('refuses the group once the student has left it', function (bool $stamped): void {
    CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->update(['closed_at' => now(), 'closed_slot' => 999]);

    $session = scopeSession(['cohort_id' => $this->saturday->getKey()]);

    bookAsStudent($session, $stamped)->assertStatus(409);

    expect(seatsHeld($session))->toBe(0);
})->with('students');

it('keeps the workspace rule for a session attached to no course', function (bool $stamped): void {
    $session = scopeSession(['course_id' => null]);

    bookAsStudent($session, $stamped)->assertCreated();

    expect(seatsHeld($session))->toBe(1);
})->with('students');

it('books with a completed enrolment, which still grants access', function (bool $stamped): void {
    Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->update(['status' => 'completed']);

    $session = scopeSession(['cohort_id' => $this->saturday->getKey()]);

    bookAsStudent($session, $stamped)->assertCreated();
})->with('students');

it('refuses an expired enrolment in the course', function (bool $stamped): void {
    Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->where('course_id', $this->maths->getKey())
        ->update(['status' => 'expired']);

    // Enrolled in physics, so the teacher-level rule alone would still say yes.
    $this->createEnrollment($this->workspace, $this->physics, $this->student);

    $session = scopeSession(['cohort_id' => $this->saturday->getKey()]);

    bookAsStudent($session, $stamped)
        ->assertStatus(409)
        ->assertJsonPath('message', 'هذه الحصة تابعة لكورس لست مسجّلاً فيه.');
})->with('students');

/*
| ⚠️ THE ONE-TO-ONE GROUP HAS NO MEMBERSHIP ROW — `ensureIndividualCohort()`
| opens none on purpose — so a membership-only check would refuse the very
| student the private hour was made for.
*/
it('books a one-to-one session filed under the student\'s own individual group', function (bool $stamped): void {
    $mine = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->maths->getKey(),
        'name' => 'حصص خاصة #'.$this->student->getKey(),
        'capacity' => 1,
    ]);
    $mine->forceFill(['individual_for_user_id' => $this->student->getKey(), 'status' => Cohort::CLOSED])->save();

    $session = scopeSession([
        'cohort_id' => $mine->getKey(),
        'type' => ClassSessionType::Individual,
        'seats_total' => 1,
    ]);

    bookAsStudent($session, $stamped)->assertCreated();
})->with('students');

it('refuses a one-to-one session filed under another student\'s individual group', function (bool $stamped): void {
    $other = User::factory()->create();

    $theirs = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->maths->getKey(),
        'name' => 'حصص خاصة #'.$other->getKey(),
        'capacity' => 1,
    ]);
    $theirs->forceFill(['individual_for_user_id' => $other->getKey(), 'status' => Cohort::CLOSED])->save();

    $session = scopeSession([
        'cohort_id' => $theirs->getKey(),
        'type' => ClassSessionType::Individual,
        'seats_total' => 1,
    ]);

    bookAsStudent($session, $stamped)->assertStatus(409);

    expect(seatsHeld($session))->toBe(0);
})->with('students');

it('still files an open 1:1 slot of the student\'s course under their own group', function (bool $stamped): void {
    $session = scopeSession([
        'type' => ClassSessionType::Individual,
        'seats_total' => 1,
    ]);

    bookAsStudent($session, $stamped)->assertCreated();

    $cohort = Cohort::query()->withoutWorkspaceScope()->find((int) $session->refresh()->cohort_id);

    expect((int) $cohort?->individual_for_user_id)->toBe((int) $this->student->getKey());
})->with('students');
