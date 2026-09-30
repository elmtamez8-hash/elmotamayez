<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on the periodic assessment. `reviews.periodic.manage` is not
| on the default assistant role; when the owner ticks it onto one, that
| assistant read, wrote and published the assessment of every student in the
| workspace — and could publish the TEACHER's draft, sending it to a guardian.
|
| Now: a confined assistant reads and writes for the students of their own
| courses, and ANY assistant publishes (and revises) only a draft they wrote.
| The teacher and the owner are unchanged.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->nearCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->farCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->near = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->far = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->nearCourse, $this->near);
    $this->createEnrollment($this->workspace, $this->farCourse, $this->far);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo(Permissions::REVIEWS_PERIODIC_MANAGE);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function prConfine(): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => test()->nearCourse->getKey(),
    ]);

    app()->forgetScopedInstances();
}

/** @return array<string, mixed> */
function prPayload(User $student, string $month = '2026-08'): array
{
    return [
        'student_uuid' => $student->uuid,
        'period_start' => $month.'-01',
        'period_end' => $month.'-28',
        'commitment' => 4,
        'participation' => 4,
        'homework' => 4,
        'improvement' => 4,
        'note' => 'ملاحظة.',
    ];
}

/** A draft written by this author, through the shipped endpoint. */
function prDraft(User $author, User $student, string $month = '2026-08'): string
{
    Sanctum::actingAs($author);
    app()->forgetScopedInstances();

    return (string) test()->postJson('/api/v1/manage/periodic-reviews', prPayload($student, $month))
        ->assertSuccessful()->json('uuid');
}

function prPublish(User $actor, string $uuid): int
{
    Sanctum::actingAs($actor);
    app()->forgetScopedInstances();

    return test()->postJson("/api/v1/manage/periodic-reviews/{$uuid}/publish")->getStatusCode();
}

it('lets a confined assistant read, write and publish for their own students and for no one else', function (): void {
    $farDraft = prDraft($this->owner, $this->far);
    prConfine();

    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/manage/students/{$this->near->uuid}/reviews")->assertOk();
    $this->getJson("/api/v1/manage/students/{$this->far->uuid}/reviews")->assertForbidden();

    $this->postJson('/api/v1/manage/periodic-reviews', prPayload($this->far))->assertUnprocessable();
    expect(PeriodicReview::query()->where('student_user_id', $this->far->getKey())->count())->toBe(1);

    $own = prDraft($this->assistant, $this->near);

    expect(prPublish($this->assistant, $own))->toBe(200)
        ->and(prPublish($this->assistant, $farDraft))->toBe(403);
});

it('lets an assistant publish and revise only the drafts they wrote, confined or not', function (): void {
    $teacherDraft = prDraft($this->owner, $this->near);

    // Unconfined: the scope passes, the authorship does not.
    expect(prPublish($this->assistant, $teacherDraft))->toBe(403);

    // Revising the teacher's draft would make the assistant its author — the
    // way around the rule above — so it is refused, and the draft is untouched.
    Sanctum::actingAs($this->assistant);
    $this->postJson('/api/v1/manage/periodic-reviews', prPayload($this->near))->assertUnprocessable();

    $row = PeriodicReview::query()->where('uuid', $teacherDraft)->firstOrFail();
    expect((int) $row->teacher_user_id)->toBe((int) $this->owner->getKey())
        ->and($row->published_at)->toBeNull();

    // Their own draft for another period is theirs to publish.
    $own = prDraft($this->assistant, $this->far, '2026-09');
    expect(prPublish($this->assistant, $own))->toBe(200);
});

it('leaves the owner every student and every draft', function (): void {
    $assistantDraft = prDraft($this->assistant, $this->near);

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/v1/manage/students/{$this->far->uuid}/reviews")->assertOk();

    expect(prPublish($this->owner, $assistantDraft))->toBe(200)
        ->and(prPublish($this->owner, prDraft($this->owner, $this->far)))->toBe(200);
});
