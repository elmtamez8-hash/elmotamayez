<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\ExecuteTeacherOffboarding;
use App\Modules\Compliance\Actions\RequestTeacherOffboarding;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| A departed teacher's chat closes for writing and stays open for reading
| (spec 013 · FR-037, reached from spec 010's side).
|
| ⚠️ THE WALK DID NOT KNOW THE CHAT EXISTED. `TeacherOffboardingCompleted` ends
| memberships, revokes tokens, unlists the profile and re-dates the recordings —
| four listeners, and not one of them touches a conversation. So a student kept
| writing private messages into a workspace with nobody left to answer, for ever,
| and the product told them nothing.
|
| ⚠️ AND THE ENROLMENT CANNOT SAY IT. FR-035 keeps the course a student PAID for
| until their term ends, which is exactly the condition `ConversationPolicy::post()`
| already reads — so the fixture below deliberately holds a LIVE enrolment, and
| asserts the post is allowed BEFORE completion and refused after it. Without that
| first half the refusal could just as well be the enrolment guard firing, and the
| test would be green for the wrong condition — this session's own lesson, and
| `US6`'s nine cases before it.
|
| ⚠️ TWO WORKSPACES, AND THE SECOND ONE IS NOT DECORATION. `ExecuteTeacherOffboarding`
| runs inside the OFFICER's request, so a guard written with the ambient workspace
| scope left on would compare against the officer's workspace and match nothing —
| green, and refusing nobody. The second workspace is what fails then.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->leaving, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'المدرّس المغادر']);
    $this->setCurrentWorkspace($this->leaving, $this->teacher);
    $this->course = Course::factory()->create(['workspace_id' => $this->leaving->getKey()]);
    $this->student = $this->addWorkspaceMember($this->leaving, Roles::STUDENT);
    $this->createEnrollment($this->leaving, $this->course, $this->student);

    [$this->staying, $this->otherTeacher] = $this->createWorkspaceWithOwner(['name' => 'المدرّس الباقي']);
    $this->setCurrentWorkspace($this->staying, $this->otherTeacher);
    $this->otherCourse = Course::factory()->create(['workspace_id' => $this->staying->getKey()]);
    $this->otherStudent = $this->addWorkspaceMember($this->staying, Roles::STUDENT);
    $this->createEnrollment($this->staying, $this->otherCourse, $this->otherStudent);

    $this->officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);
});

/** Completes the exit for a workspace, notice period and all. */
function completeExit(object $test, object $workspace, object $teacher): void
{
    $offboarding = app(RequestTeacherOffboarding::class)->handle($workspace, $teacher);

    /*
    | FR-033's announced notice is part of the completion guard, so a fixture that
    | skipped it would be measuring the deadline instead of the exit.
    |
    | ⚠️ `withoutWorkspaceScope()`, AND THE FIRST DRAFT OF THIS HELPER OMITTED IT
    | — the exact failure this file's second workspace exists to expose. The
    | ambient context here is the OTHER teacher's workspace, so the scoped form
    | ANDed the wrong `workspace_id`, matched zero rows and left the notice in
    | place. In a one-workspace fixture it would have passed and proved nothing.
    */
    TeacherOffboarding::query()
        ->withoutWorkspaceScope()
        ->whereKey($offboarding->getKey())
        ->update(['notice_ends_at' => now()->subDay()]);

    app(ExecuteTeacherOffboarding::class)->handle($offboarding->refresh(), $test->officer);
}

it('stops the student writing once the exit completes, and not before', function (): void {
    Queue::fake();

    Sanctum::actingAs($this->student);

    $uuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $this->leaving->uuid,
    ])->assertCreated()->json('uuid');

    // The control. A live enrolment, no ban, notice still running: the door is
    // open, so the refusal below can only be the exit.
    $this->postJson("/api/v1/conversations/{$uuid}/messages", [
        'body' => 'أستاذ، سؤال عن الواجب.',
    ])->assertCreated();

    completeExit($this, $this->leaving, $this->teacher);

    /*
    | ⚠️ THE MEMO IS DROPPED BY HAND, AND ONLY BECAUSE A TEST SHARES ONE CONTAINER
    | ACROSS REQUESTS. `TeacherOffboardingDirectory` is bound `scoped()`, so in
    | production the answer lives exactly as long as one request — php-fpm builds a
    | fresh container per request and the queue worker calls this same method
    | between jobs, which is why `scoped()` was chosen over `singleton()`. Here the
    | control post above resolved it and memoised «has not departed», and without
    | this line the assertion would be measuring that stale `false` rather than the
    | guard. Deleting it makes the test fail, not pass — it is not a workaround for
    | a defect, it is the price of asserting both halves in one process.
    */
    app()->forgetScopedInstances();

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/conversations/{$uuid}/messages", [
        'body' => 'أستاذ؟',
    ])->assertForbidden();
});

it('leaves the archive readable, because reading and writing are two abilities', function (): void {
    Queue::fake();

    Sanctum::actingAs($this->student);

    $uuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $this->leaving->uuid,
    ])->assertCreated()->json('uuid');

    $this->postJson("/api/v1/conversations/{$uuid}/messages", [
        'body' => 'شكراً على الشرح.',
    ])->assertCreated();

    completeExit($this, $this->leaving, $this->teacher);

    Sanctum::actingAs($this->student);

    // FR-014: the conversation a student paid for their lessons in does not
    // disappear on the day the other side leaves.
    $this->getJson("/api/v1/conversations/{$uuid}/messages")
        ->assertOk()
        ->assertJsonCount(1);
});

it('refuses to open a NEW thread after the exit, which is the other door', function (): void {
    Queue::fake();

    // ⚠️ NOBODY OPENED A THREAD HERE FIRST, ON PURPOSE. `StartConversation`
    // authorises an UNSAVED `Conversation` against `post`, so a guard stamped on
    // an existing row could not have answered this call at all — it is the reason
    // the question lives in the policy and not in a column.
    completeExit($this, $this->leaving, $this->teacher);

    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/conversations', [
        'workspace' => $this->leaving->uuid,
    ])->assertForbidden();
});

it('does not touch the teacher who is staying', function (): void {
    Queue::fake();

    completeExit($this, $this->leaving, $this->teacher);

    Sanctum::actingAs($this->otherStudent);

    $uuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $this->staying->uuid,
    ])->assertCreated()->json('uuid');

    $this->postJson("/api/v1/conversations/{$uuid}/messages", [
        'body' => 'صباح الخير.',
    ])->assertCreated();
});
