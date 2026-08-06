<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\GetStudentSchedule;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| NFR-001ب — the test the constitution requires for every platform-owned entity,
| in the same PR that adds it.
|
| The student's schedule crosses workspace boundaries on purpose: one person has
| one timetable, not one per teacher. That makes it as exposed as an
| unauthenticated marketplace query, because no global scope touches it. Both
| directions are asserted here, because getting either one wrong is silent:
|
|  - too narrow, and a student sees a different timetable per teacher — the
|    mirror-image bug of adding BelongsToWorkspace where it does not belong.
|  - too wide, and a teacher reads their student's sessions with a competitor.
*/

beforeEach(function (): void {
    // Two teachers, two workspaces, one student studying with both.
    [$this->workspaceA, $this->ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$this->workspaceB, $this->ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $this->student = User::factory()->create();

    $this->sessionA = seedSessionFor($this->workspaceA, $this->ownerA, $this->student);
    $this->sessionB = seedSessionFor($this->workspaceB, $this->ownerB, $this->student);
});

/** Enrols the student with one teacher and books them a seat. */
function seedSessionFor($workspace, $owner, $student): ClassSession
{
    $test = test();
    $test->setCurrentWorkspace($workspace, $owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $owner->getKey()]);
    // CourseFactory hard-codes workspace_id = 1, and an explicit attribute wins
    // over BelongsToWorkspace's auto-fill — so the second workspace's course
    // would silently belong to the first, and $enrollment->course would resolve
    // to null under its own scope.
    $course = Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $test->addWorkspaceMember($workspace, Roles::STUDENT, $student);
    $test->createEnrollment($workspace, $course, $student);
    $test->setCurrentWorkspace($workspace, $owner);

    $session = ClassSession::factory()->create(['teacher_profile_id' => $teacher->getKey()]);
    app(BookSeat::class)->handle($session, $student);

    return $session;
}

it('gives the student ONE schedule across every teacher', function (): void {
    $bookings = app(GetStudentSchedule::class)->handle($this->student);

    expect($bookings)->toHaveCount(2)
        ->and($bookings->pluck('class_session_id')->all())
        ->toEqualCanonicalizing([$this->sessionA->getKey(), $this->sessionB->getKey()]);
});

it('does not duplicate the student per teacher', function (): void {
    // The mirror-image bug: BelongsToWorkspace on a platform-owned read path
    // would produce one timetable per workspace, so the same person would see a
    // different half of their week depending on which teacher's context loaded.
    $uuids = app(GetStudentSchedule::class)->handle($this->student)->pluck('uuid');

    expect($uuids->unique())->toHaveCount(2);
});

it('never shows a teacher their student sessions with another teacher', function (): void {
    Sanctum::actingAs($this->ownerA);
    $this->setCurrentWorkspace($this->workspaceA, $this->ownerA);

    // The teacher's own listing is workspace-scoped and must show one session.
    $response = $this->getJson('/api/v1/class-sessions')->assertOk();
    $uuids = collect($response->json('data'))->pluck('uuid');

    expect($uuids)->toHaveCount(1)
        ->and($uuids->first())->toBe($this->sessionA->uuid)
        ->and($uuids)->not->toContain($this->sessionB->uuid);
});

it('answers /schedule with the reader own bookings, never someone else', function (): void {
    Sanctum::actingAs($this->ownerA);

    // There is no parameter by which to ask for another person's timetable, and
    // the teacher has no bookings of their own — so the honest answer is empty.
    $this->getJson('/api/v1/schedule')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    Sanctum::actingAs($this->student);

    $this->getJson('/api/v1/schedule')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
