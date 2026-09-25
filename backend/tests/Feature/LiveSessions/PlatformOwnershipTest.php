<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\GetStudentSchedule;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
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

it('orders the timetable by the lesson\'s start and reads ONE booking for next()', function (): void {
    /*
    | The order used to be a PHP sort over every upcoming booking, loaded with
    | its session, course and teacher; the countdown asked for one and paid for
    | the term. The order now comes from SQL, so it is asserted against two
    | sessions created in the OPPOSITE order to their start times — an
    | id-ordered read would pass a test where the two agree.
    */
    ClassSession::query()->withoutWorkspaceScope()->whereKey($this->sessionA->getKey())
        ->update(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);
    ClassSession::query()->withoutWorkspaceScope()->whereKey($this->sessionB->getKey())
        ->update(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

    expect(app(GetStudentSchedule::class)->handle($this->student)->pluck('class_session_id')->all())
        ->toBe([$this->sessionB->getKey(), $this->sessionA->getKey()]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $next = app(GetStudentSchedule::class)->next($this->student);
    $bookingRead = collect(DB::getQueryLog())->pluck('query')
        ->first(fn (string $sql): bool => str_starts_with($sql, 'select * from "session_bookings"'));
    DB::disableQueryLog();

    expect($next?->class_session_id)->toBe($this->sessionB->getKey())
        ->and($bookingRead)->toContain('limit 1');
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
