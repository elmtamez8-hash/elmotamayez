<?php

declare(strict_types=1);

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| Security scan 2026-10-10 — F25 · F26 · F23. An assistant confined to course A,
| whom the owner granted the permission, acts on course A only: publishing,
| unpublishing, deleting, and the grade weights.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->mine = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'created_by' => $this->owner->getKey()]);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey(), 'created_by' => $this->owner->getKey()]);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo([
        Permissions::COURSES_PUBLISH,
        Permissions::COURSES_DELETE,
        Permissions::REVIEWS_PERIODIC_MANAGE,
    ]);

    $assignment = AssistantAssignment::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $assignment->getKey(),
        'course_id' => $this->mine->getKey(),
    ]);
    app()->forgetScopedInstances();

    $this->setCurrentWorkspace($this->workspace, $this->assistant);
    Sanctum::actingAs($this->assistant);
});

it('refuses a confined assistant publishing or unpublishing a course outside their scope', function (): void {
    $this->postJson("/api/v1/courses/{$this->far->uuid}/unpublish")->assertForbidden();

    expect($this->far->fresh()->getRawOriginal('status'))->toBe('published');
});

it('refuses a confined assistant deleting a course outside their scope', function (): void {
    $this->deleteJson("/api/v1/courses/{$this->far->uuid}")->assertForbidden();

    expect(Course::query()->whereKey($this->far->getKey())->exists())->toBeTrue();
});

it('refuses a confined assistant the workspace-wide grade weights', function (): void {
    $this->postJson('/api/v1/manage/grading-schemes', [
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'weights' => ['exams' => 0, 'homework' => 100, 'participation' => 0, 'attendance' => 0],
    ])->assertForbidden();
});
