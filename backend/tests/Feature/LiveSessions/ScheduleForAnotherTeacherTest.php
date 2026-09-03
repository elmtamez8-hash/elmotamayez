<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\Sanctum;

/*
| ⚠️ A TEACHER COULD PUT A LESSON ON A COLLEAGUE'S CALENDAR, AND NOTHING ASKED.
|
| `ClassSessionPolicy::create(User $user)` is handed the CLASS, never the profile,
| so all it can answer is «may you schedule at all» — and `sessions.manage` sits on
| the TEACHER role, not the owner's alone. The named `teacher_profile_uuid` then
| travelled from the request into `ClassSession::create()` with `$actor` used for
| `created_by` and nothing else. Its one check, `WorkspaceRules::exists`, is
| satisfied by a colleague BY DEFINITION.
|
| Since 014 the teacher is paid FROM DELIVERY, so a session on someone's calendar
| becomes their teaching unit and their ledger entry the moment it is taught.
|
| ⚠️ THE FIXTURE IS ONE WORKSPACE WITH TWO TEACHER PROFILES, not the usual two
| workspaces. The cross-workspace case was never open — `WorkspaceRules::exists`
| closes it upstream — so a two-workspace fixture would measure a door that was
| already shut and report the open one as green.
|
| ⚠️ AND THE PREDICATE IS A DISJUNCTION, so each arm neutralises the other or the
| test measures whichever fires first (the `US6` lesson): the refusal case uses an
| actor who is a teacher and NOT the owner, and the owner case uses an owner
| scheduling for a profile that is not their own.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // Two teachers in ONE academy — the shape the defect lives in.
    $this->mine = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);

    $this->colleagueUser = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);

    $this->colleague = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->colleagueUser->getKey(),
    ]);

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
});

function scheduleFor(TeacherProfile $profile, Course $course): ScheduleSessionData
{
    return ScheduleSessionData::fromArray([
        'teacher_profile_id' => $profile->getKey(),
        'course_id' => $course->getKey(),
        'title' => 'حصة',
        'type' => ClassSessionType::Individual->value,
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(10, 0)->toDateTimeString(),
        'duration_minutes' => 60,
        'seats_total' => 1,
    ]);
}

it('refuses a teacher scheduling onto a colleague calendar', function (): void {
    // The colleague is a teacher and NOT the owner — so the owner arm of the
    // predicate is neutralised and the refusal can only come from the other one.
    expect(fn () => app(ScheduleClassSession::class)->handle(
        scheduleFor($this->mine, $this->course),
        $this->colleagueUser,
    ))->toThrow(AuthorizationException::class);

    // Read the ROW, not the exception: a guard that throws after writing is a
    // guard that did not guard.
    expect(ClassSession::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('lets a teacher schedule on their own calendar', function (): void {
    // ⚠️ THE ALLOW DIRECTION IS THE ONE THAT CATCHES A GUARD THAT REFUSES
    // EVERYTHING — a deny-only test passes just as well against a wall.
    $session = app(ScheduleClassSession::class)->handle(
        scheduleFor($this->colleague, $this->course),
        $this->colleagueUser,
    );

    expect($session->teacher_profile_id)->toBe($this->colleague->getKey());
});

it('lets the workspace owner schedule for any of their teachers', function (): void {
    // The owner scheduling for a profile that is NOT theirs — the other arm, with
    // the «own profile» arm neutralised by choosing the colleague's.
    $session = app(ScheduleClassSession::class)->handle(
        scheduleFor($this->colleague, $this->course),
        $this->owner,
    );

    expect($session->teacher_profile_id)->toBe($this->colleague->getKey());
});

it('refuses over HTTP too, so the guard is not only reachable from a test', function (): void {
    Sanctum::actingAs($this->colleagueUser);
    $this->setCurrentWorkspace($this->workspace, $this->colleagueUser);

    $response = $this->postJson('/api/v1/class-sessions', [
        'teacher_profile_uuid' => $this->mine->uuid,
        'course_uuid' => $this->course->uuid,
        'title' => 'حصة',
        'type' => ClassSessionType::Individual->value,
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(10, 0)->toIso8601String(),
        'duration_minutes' => 60,
        'seats_total' => 1,
    ]);

    // 403, not 422: `ClassSessionController::store()` catches `DomainException`
    // only, so an `AuthorizationException` travels to Laravel's own handler —
    // which is the right code for «not yours», and is asserted rather than assumed.
    $response->assertForbidden();
    expect(ClassSession::query()->withoutGlobalScopes()->count())->toBe(0);
});

/*
| ⚠️ AND THE ORDINARY REQUEST NAMES NOBODY AT ALL.
|
| This platform is one independent teacher per workspace — not an academy with
| staff underneath — so «which teacher» is a question with one answer, and the
| picker that asked it gated the create button of a DIFFERENT card while sitting
| in its own. The field is optional on the wire now and the server resolves the
| caller's own profile; a named one is still accepted, and still judged.
*/
it('schedules for the signed-in teacher when the payload names nobody', function (): void {
    Sanctum::actingAs($this->colleagueUser);
    $this->setCurrentWorkspace($this->workspace, $this->colleagueUser);

    $response = $this->postJson('/api/v1/class-sessions', [
        // no teacher_profile_uuid
        'course_uuid' => $this->course->uuid,
        'title' => 'حصة',
        'type' => ClassSessionType::Individual->value,
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(10, 0)->toIso8601String(),
        'duration_minutes' => 60,
        'seats_total' => 1,
    ]);

    $response->assertCreated();

    // The ROW, not the response: the payload echoes what it was given.
    expect(ClassSession::query()->withoutGlobalScopes()->sole()->teacher_profile_id)
        ->toBe($this->colleague->getKey());
});

it('refuses when the caller has no teacher profile of their own', function (): void {
    /*
     * ⚠️ A TEACHER ROLE WITH NO PROFILE ROW, not an assistant. An assistant is
     * refused 403 by `ClassSessionPolicy::create()` before validation is ever
     * reached — correct, and a different door. The case this guards is the one
     * that gets PAST the policy: somebody holding `sessions.manage` for whom
     * `teacher_profiles` has no row, which is what a derived field turns from a
     * missing parameter into a 500 if nobody names it.
     */
    $bystander = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);

    Sanctum::actingAs($bystander);
    $this->setCurrentWorkspace($this->workspace, $bystander);

    $response = $this->postJson('/api/v1/class-sessions', [
        'course_uuid' => $this->course->uuid,
        'title' => 'حصة',
        'type' => ClassSessionType::Individual->value,
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(10, 0)->toIso8601String(),
        'duration_minutes' => 60,
        'seats_total' => 1,
    ]);

    // 422 on the field, not a 500 further down: «you have no teacher profile» is
    // an answerable sentence, and it lands where a form can print it.
    $response->assertStatus(422)->assertJsonValidationErrors('teacher_profile_uuid');
    expect(ClassSession::query()->withoutGlobalScopes()->count())->toBe(0);
});
