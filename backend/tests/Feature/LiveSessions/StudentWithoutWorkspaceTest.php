<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| The student production actually has, which no test in this repository had.
|
| ⚠️ EVERY OTHER LiveSessions TEST BUILDS ITS STUDENT WITH `addWorkspaceMember()`,
| and that helper does two things production never does: it attaches the user to
| `workspace_members` and it stamps `users.last_workspace_id`. A real student is
| neither — only `CreateWorkspace` and `WorkspaceContext::set()` (reached from
| `AcceptInvitation` and `SwitchWorkspace`, both about MEMBERS) ever write that
| column, and the enrolment path writes nothing at all. The two seeders stamp it
| on their demo students, so a manual walk could not see it either.
|
| What that hid: `WorkspaceContext::id()` is null for every real student, so
| `ClassSessionPolicy::view()` denied on `belongsToCurrentWorkspace()` — and would
| have denied a second time anyway, because with no team id spatie hands out no
| roles, making `can(SESSIONS_VIEW)` false for the same person for the same
| reason. FOUR endpoints authorise on that one ability and all four were dead:
| booking a seat, opening the session, reading one's own attendance row, and
| asking WHY a join was refused — `FR-038`'s entire answer, which `UnlockNotice`
| fetches at exactly that moment and swallows on failure. The student read
| «تعذّر الدخول» and nothing else, permanently.
|
| Found on 2026-08-26 by the `T051` walk, against a live LiveKit project, by
| nulling that one column on a student who worked and watching four endpoints
| turn 403.
|
| ⚠️ THE `resetWorkspaceContext()` CALL IS LOAD-BEARING. The context is an
| application-wide singleton that FREEZES its answer on the first `id()`; the
| fixture below resolves it to the teacher's workspace while building, and
| without the reset the student's request inherits that resolution, the policy
| passes, and this file goes green against the unfixed code — proving the
| opposite of what it claims.
*/

beforeEach(function (): void {
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
    ]);
});

/** Drop the cached resolution so the next request resolves as the caller would. */
function resetWorkspaceContext(): void
{
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
}

/** A student the way the product makes one: an enrolment, and nothing else. */
function enrolledStudent(): User
{
    $student = User::factory()->create();
    test()->createEnrollment(test()->workspace, test()->course, $student);

    expect($student->refresh()->last_workspace_id)->toBeNull();

    return $student;
}

it('lets an enrolled student read the session, its register and its refusal reason', function (): void {
    $student = enrolledStudent();
    $uuid = $this->session->uuid;

    Sanctum::actingAs($student);
    resetWorkspaceContext();

    $this->getJson("/api/v1/class-sessions/{$uuid}")->assertOk();
    $this->getJson("/api/v1/class-sessions/{$uuid}/attendance")->assertOk();

    // FR-038: the sentence that tells a blocked student what to go and do. It is
    // fetched the instant a join is refused, so a 403 here is silence at the one
    // moment the requirement is about.
    $this->getJson("/api/v1/class-sessions/{$uuid}/eligibility")
        ->assertOk()
        ->assertJsonPath('data.session_uuid', $uuid);
});

it('lets an enrolled student reach the booking rules instead of a wall in front of them', function (): void {
    $student = enrolledStudent();

    Sanctum::actingAs($student);
    resetWorkspaceContext();

    // Whatever the booking rules decide, the answer must come FROM them. A 403
    // here is the authorisation layer refusing before any rule has been read,
    // which is a student who can never book a session at all.
    expect($this->postJson('/api/v1/class-sessions/'.$this->session->uuid.'/book')->status())
        ->not->toBe(403);
});

it('lets a seat holder read the session even with no active enrolment left', function (): void {
    // The person FR-038 is most about: the enrolment lapsed, the nightly sweep
    // cancelled the seat, and the explanation is all they have. A booked-only
    // read would refuse them exactly the sentence that names the cause.
    $student = User::factory()->create();

    app(WorkspaceContext::class)->set($this->workspace);
    SessionBooking::create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => 'released',
        'is_billable' => false,
        'booked_at' => now(),
        'cancelled_at' => now(),
    ]);

    Sanctum::actingAs($student);
    resetWorkspaceContext();

    $this->getJson('/api/v1/class-sessions/'.$this->session->uuid.'/eligibility')->assertOk();
});

it('still refuses a stranger with no seat and no enrolment', function (): void {
    // The control, in the direction that matters: the fix is ownership, not a
    // hole. Without this case the three above would also pass against a policy
    // that allowed everybody.
    $stranger = User::factory()->create();
    $uuid = $this->session->uuid;

    Sanctum::actingAs($stranger);
    resetWorkspaceContext();

    $this->getJson("/api/v1/class-sessions/{$uuid}")->assertForbidden();
    $this->getJson("/api/v1/class-sessions/{$uuid}/eligibility")->assertForbidden();
    $this->getJson("/api/v1/class-sessions/{$uuid}/attendance")->assertForbidden();
    $this->postJson("/api/v1/class-sessions/{$uuid}/book")->assertForbidden();
});
