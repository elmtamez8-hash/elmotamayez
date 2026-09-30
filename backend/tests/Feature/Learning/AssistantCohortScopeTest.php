<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| Spec 010 · FR-005 on the teacher's cohort screens (audit 2026-09-30).
|
| ⚠️ `CohortPolicy` and `CohortTransferRequestPolicy` asked the workspace and
| `courses.update` and nothing else, so an assistant confined to ONE course read
| and ran every other course's groups: the roll with names, the history, the
| transfer queue, «add a student», «archive». Every door here is asked twice —
| the course the assistant works on answers, the other one refuses.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $course = fn (string $title): Course => Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
        'course_type' => Course::TYPE_GROUP,
        'title' => $title,
    ]);

    $this->near = $course('قريب');
    $this->far = $course('بعيد');

    $this->student = User::factory()->create();

    foreach ([$this->near, $this->far] as $c) {
        groupPriceFor($c);

        Enrollment::create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $c->getKey(),
            'student_user_id' => $this->student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);
    }

    $cohort = fn (Course $c, string $name): Cohort => Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $c->getKey(),
        'created_by' => $this->owner->getKey(),
        'name' => $name,
    ]);

    $this->nearA = $cohort($this->near, 'قريب أ');
    $this->nearB = $cohort($this->near, 'قريب ب');
    $this->farA = $cohort($this->far, 'بعيد أ');
    $this->farB = $cohort($this->far, 'بعيد ب');

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function confineCohortAssistantTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    app()->forgetScopedInstances();
}

function seatInCohort(Cohort $cohort, User $student): void
{
    CohortMembership::query()->create([
        'workspace_id' => $cohort->workspace_id,
        'course_id' => $cohort->course_id,
        'cohort_id' => $cohort->getKey(),
        'student_user_id' => $student->getKey(),
        'joined_at' => now(),
    ]);
}

function pendingTransfer(Cohort $from, Cohort $to, User $student): CohortTransferRequest
{
    return CohortTransferRequest::factory()->create([
        'workspace_id' => $to->workspace_id,
        'course_id' => $to->course_id,
        'student_user_id' => $student->getKey(),
        'from_cohort_id' => $from->getKey(),
        'to_cohort_id' => $to->getKey(),
    ]);
}

/**
 * Every teacher-side cohort door, as `[method, url, body]`, for one course.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function cohortDoors(Course $course, Cohort $cohort, CohortTransferRequest $request, User $student): array
{
    return [
        'index' => ['get', "/api/v1/manage/courses/{$course->uuid}/cohorts", []],
        'store' => ['post', "/api/v1/manage/courses/{$course->uuid}/cohorts", ['name' => 'جديدة']],
        'show' => ['get', "/api/v1/manage/cohorts/{$cohort->uuid}", []],
        'update' => ['patch', "/api/v1/manage/cohorts/{$cohort->uuid}", ['name' => 'معدَّلة']],
        'members' => ['get', "/api/v1/manage/cohorts/{$cohort->uuid}/members", []],
        'eligible' => ['get', "/api/v1/manage/cohorts/{$cohort->uuid}/eligible-students", []],
        'history' => ['get', "/api/v1/manage/cohorts/{$cohort->uuid}/history", []],
        'student-history' => ['get', "/api/v1/manage/courses/{$course->uuid}/students/{$student->uuid}/cohort-history", []],
        'transfer-requests' => ['get', "/api/v1/manage/courses/{$course->uuid}/transfer-requests", []],
        // Ordered so each write is legal on the course it may act on: the pending
        // request is decided while the student is still seated, then they are
        // taken out and put back.
        'reject' => ['post', "/api/v1/manage/transfer-requests/{$request->uuid}/reject", ['reason' => 'لا']],
        'remove-member' => ['delete', "/api/v1/manage/cohorts/{$cohort->uuid}/members/{$student->uuid}", []],
        'add-member' => ['post', "/api/v1/manage/cohorts/{$cohort->uuid}/members", ['student_uuid' => $student->uuid]],
        // Last: archiving ends the group the others read.
        'archive' => ['post', "/api/v1/manage/cohorts/{$cohort->uuid}/archive", []],
    ];
}

it('refuses a confined assistant every cohort door of a course outside their scope', function (): void {
    confineCohortAssistantTo($this->near);
    seatInCohort($this->farA, $this->student);
    $request = pendingTransfer($this->farA, $this->farB, $this->student);

    Sanctum::actingAs($this->assistant);

    foreach (cohortDoors($this->far, $this->farA, $request, $this->student) as $door => [$method, $url, $body]) {
        expect($this->json($method, $url, $body)->status())->toBe(403, "far door {$door}");
    }

    $approve = pendingTransfer($this->farA, $this->farB, User::factory()->create());
    $this->postJson("/api/v1/manage/transfer-requests/{$approve->uuid}/approve")->assertForbidden();

    // Nothing moved.
    expect($this->farA->refresh()->archived_at)->toBeNull()
        ->and($this->farA->name)->toBe('بعيد أ')
        ->and($request->refresh()->status)->toBe(CohortTransferRequest::PENDING);
});

it('lets the same confined assistant through every cohort door of their own course', function (): void {
    confineCohortAssistantTo($this->near);
    seatInCohort($this->nearA, $this->student);
    $request = pendingTransfer($this->nearA, $this->nearB, $this->student);

    Sanctum::actingAs($this->assistant);

    foreach (cohortDoors($this->near, $this->nearA, $request, $this->student) as $door => [$method, $url, $body]) {
        $status = $this->json($method, $url, $body)->status();

        expect($status)->toBeLessThan(300, "near door {$door} answered {$status}");
    }
});

it('leaves an unconfined assistant and the owner on every course', function (): void {
    foreach ([$this->assistant, $this->owner] as $reader) {
        Sanctum::actingAs($reader);

        $this->getJson("/api/v1/manage/courses/{$this->far->uuid}/cohorts")->assertOk();
        $this->getJson("/api/v1/manage/cohorts/{$this->farA->uuid}/members")->assertOk();
        $this->getJson("/api/v1/manage/courses/{$this->far->uuid}/transfer-requests")->assertOk();
        $this->patchJson("/api/v1/manage/cohorts/{$this->farB->uuid}", ['name' => 'بعيد ب'])->assertOk();
    }
});

it('still hides another workspace\'s groups from a confined assistant', function (): void {
    confineCohortAssistantTo($this->near);

    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $theirs = app(WorkspaceContext::class)->forWorkspace($other, function () use ($other, $otherOwner): Cohort {
        $course = Course::factory()->published()->create([
            'workspace_id' => $other->getKey(),
            'created_by' => $otherOwner->getKey(),
            'course_type' => Course::TYPE_GROUP,
        ]);

        return Cohort::factory()->create([
            'workspace_id' => $other->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $otherOwner->getKey(),
        ]);
    });

    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/manage/cohorts/{$theirs->uuid}")->assertNotFound();
    $this->getJson("/api/v1/manage/cohorts/{$theirs->uuid}/members")->assertNotFound();
});

it('leaves the student\'s own picker alone', function (): void {
    confineCohortAssistantTo($this->near);

    $this->asGuest();
    Sanctum::actingAs($this->student);

    $this->getJson("/api/v1/courses/{$this->far->uuid}/cohorts")->assertOk();
});
