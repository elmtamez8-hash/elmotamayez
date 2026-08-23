<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Answer;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Policies\AttendancePolicy;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\AssistantScopeDirectory;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-002 · FR-004 · FR-005 — an assistant confined to a course is refused outside
| it, and everything they hold stays true inside it.
|
| ⚠️ BOTH DIRECTIONS IN ONE TEST. A refusal on the far course proves nothing on
| its own: an assistant with no `lessons.manage` is refused on BOTH courses, and
| the file would be green against a product with no scope in it. The 200 on the
| near course is what makes the 403 mean «outside your scope» rather than
| «you hold nothing».
|
| ⚠️ AND THE PRIVATE CONVERSATION IS THE HALF WITH NO COURSE ON IT. A conversation
| between an assistant and a student names no course, so the confinement can only
| be evaluated by INTERSECTING the student's live enrolments with the scope.
| Without that, an assistant confined to one course reads every private
| conversation in the workspace — the confinement present in the data and absent
| from the only surface that matters. `US2` builds the conversation; the question
| it will ask is `mayActOnStudent()`, and it is measured here where the scope is.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->far = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    AssistantScope::factory()->create([
        'assistant_assignment_id' => $assignment->getKey(),
        'course_id' => $this->near->getKey(),
    ]);
});

it('opens the course inside the scope and refuses the one outside it', function (): void {
    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/courses/{$this->near->uuid}/tree")->assertOk();
    $this->getJson("/api/v1/courses/{$this->far->uuid}/tree")->assertForbidden();
});

it('confines the assistant to the students of the courses they were given', function (): void {
    $inside = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $outside = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->createEnrollment($this->workspace, $this->near, $inside);
    $this->createEnrollment($this->workspace, $this->far, $outside);

    $directory = app(AssistantScopeDirectory::class);
    $workspaceId = (int) $this->workspace->getKey();

    expect($directory->mayActOnStudent($this->assistant, $workspaceId, (int) $inside->getKey()))->toBeTrue()
        ->and($directory->mayActOnStudent($this->assistant, $workspaceId, (int) $outside->getKey()))->toBeFalse();
});

it('refuses a confined assistant a paper from a course they were not given', function (): void {
    /*
    | ⚠️ THE REFUSING BRANCH OF THE GRADING GUARD, WHICH NOTHING ELSE MEASURES.
    | `AssistantAttributionTest`'s assistant is unconfined, so `GradingPolicy`'s
    | scope check passes there without ever evaluating a confinement — and the
    | chain it walks, `answer → attempt → exam → course_id`, fails SILENT in the
    | one direction that matters: a broken relation reads as «no course», and a
    | confined assistant would be allowed to mark anything. Both directions in
    | one test, because a 403 alone would also be produced by an assistant who
    | simply holds no grading permission.
    */
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->assistant->givePermissionTo(Permissions::GRADING_PERFORM);

    // Two papers: one sat on the course inside the scope, one outside it. The
    // helper makes its own course, so the scope is widened to include the near
    // one deliberately and left excluding the far one.
    [, $farAttempt] = sitEssayExam($this->workspace, $student);

    $farAnswer = Answer::query()
        ->where('attempt_id', $farAttempt->getKey())
        ->where('requires_grading', true)
        ->whereNull('graded_at')
        ->firstOrFail();

    $marks = ['marks' => [['criterion_id' => null, 'points' => 5, 'comment' => 'جيّد.']]];

    Sanctum::actingAs($this->assistant);

    $this->postJson("/api/v1/manage/grading/answers/{$farAnswer->uuid}", $marks)->assertForbidden();

    // Now widen the confinement to that exam's own course and the same request
    // goes through — which is what makes the 403 above mean «outside your scope»
    // rather than «you hold nothing».
    $examCourseId = $farAnswer->attempt?->exam?->course_id;

    AssistantScope::query()->create([
        'assistant_assignment_id' => AssistantAssignment::query()
            ->where('assistant_user_id', $this->assistant->getKey())
            ->value('id'),
        'course_id' => $examCourseId,
    ]);

    // A fresh container drops the directory's per-request memo, which is what an
    // actual next request does — the confinement is read once per request.
    app()->forgetScopedInstances();

    Sanctum::actingAs($this->assistant->refresh());

    $this->postJson("/api/v1/manage/grading/answers/{$farAnswer->uuid}", $marks)->assertOk();
});

it('refuses a confined assistant the register of a session outside their courses', function (): void {
    /*
    | ⚠️ THE THIRD SURFACE, MEASURED ON THE POLICY RATHER THAN OVER HTTP. The
    | route's Action enforces an edit WINDOW on top of the policy (a business
    | rule about time, which Constitution II keeps in the Action), so a passing
    | control over HTTP would be answering a question about the clock. The chain
    | `attendance → classSession → course_id` is what fails silent, and it is the
    | same shape as grading's — asserted here in both directions.
    */
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $nearSession = billableSession($this->workspace, $this->owner, $this->near);
    $farSession = billableSession($this->workspace, $this->owner, $this->far);

    $inside = attendanceRow($this->workspace, $nearSession, $student, AttendanceStatus::Absent);
    $outside = attendanceRow($this->workspace, $farSession, $student, AttendanceStatus::Absent);

    $policy = new AttendancePolicy;

    expect($policy->override($this->assistant, $outside)->denied())->toBeTrue()
        // Inside the scope the scope check passes and the ordinary permission
        // question takes over — which is what makes the refusal above the
        // confinement rather than a missing grant.
        ->and($policy->override($this->assistant, $inside)->denied())->toBeTrue();

    $this->assistant->givePermissionTo(Permissions::ATTENDANCE_OVERRIDE);
    app()->forgetScopedInstances();

    expect($policy->override($this->assistant->refresh(), $inside)->allowed())->toBeTrue()
        ->and($policy->override($this->assistant, $outside)->denied())->toBeTrue();
});

it('reads an unconfined assistant as every course, never as none', function (): void {
    /*
    | ⚠️ NO ROWS MEANS NO CONFINEMENT, and the opposite reading is the defect
    | that ships silently: an assistant is invited before anyone chooses their
    | courses, so «empty scope = no courses» refuses every assistant everything
    | from the moment they accept. There is no «all courses» column precisely
    | because a second way to say the same thing is a second thing to get wrong.
    */
    $unconfined = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    AssistantAssignment::factory()->create([
        'assistant_user_id' => $unconfined->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    Sanctum::actingAs($unconfined);

    $this->getJson("/api/v1/courses/{$this->near->uuid}/tree")->assertOk();
    $this->getJson("/api/v1/courses/{$this->far->uuid}/tree")->assertOk();
});
