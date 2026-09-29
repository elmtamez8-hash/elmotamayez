<?php

declare(strict_types=1);

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| A confined assistant READS the courses they were given — the list and the
| draft door — and nothing else in the workspace (spec 010 · FR-005, on reads).
|
| ⚠️ BOTH DIRECTIONS IN EVERY TEST. «far is absent» on its own is green against
| an assistant who can list nothing at all; «near is present» beside it is what
| makes the absence mean «outside your scope».
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'title' => 'قريب', 'status' => 'draft']);
    $this->far = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'title' => 'بعيد', 'status' => 'draft']);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function confineTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    // The directory memoises per request; a fresh container is the next request.
    app()->forgetScopedInstances();
}

/** @return list<string> */
function listedCourseUuids(string $query = ''): array
{
    return collect(test()->getJson('/api/v1/courses'.$query)->assertOk()->json('data'))
        ->pluck('uuid')
        ->all();
}

it('lists only the courses a confined assistant was given', function (): void {
    confineTo($this->near);

    Sanctum::actingAs($this->assistant);

    expect(listedCourseUuids())->toBe([$this->near->uuid]);
    // The search branch too — it must not bypass the confinement.
    expect(listedCourseUuids('?search='.urlencode('قريب')))->toBe([$this->near->uuid])
        ->and(listedCourseUuids('?search='.urlencode('بعيد')))->toBe([]);
});

it('lists every course to an assistant with an empty scope, and to the owner', function (): void {
    Sanctum::actingAs($this->assistant);
    expect(listedCourseUuids())->toEqualCanonicalizing([$this->near->uuid, $this->far->uuid]);

    Sanctum::actingAs($this->owner);
    expect(listedCourseUuids())->toEqualCanonicalizing([$this->near->uuid, $this->far->uuid]);
});

it('opens a draft inside the scope and refuses the one outside it', function (): void {
    confineTo($this->near);

    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/courses/{$this->near->uuid}")->assertOk();
    $this->getJson("/api/v1/courses/{$this->far->uuid}")->assertForbidden();
});

it('still opens a published course outside the scope — published is public', function (): void {
    confineTo($this->near);
    $this->far->forceFill(['status' => 'published'])->save();

    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/courses/{$this->far->uuid}")->assertOk();
});

it('opens every draft to an unconfined assistant', function (): void {
    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/courses/{$this->near->uuid}")->assertOk();
    $this->getJson("/api/v1/courses/{$this->far->uuid}")->assertOk();
});

it('never lists another workspace\'s course, confined or not', function (): void {
    [$otherWorkspace] = $this->createWorkspaceWithOwner();

    $foreign = app(WorkspaceContext::class)->forWorkspace(
        $otherWorkspace,
        fn (): Course => Course::factory()->create(['workspace_id' => $otherWorkspace->getKey(), 'status' => 'draft']),
    );

    Sanctum::actingAs($this->assistant);
    expect(listedCourseUuids())->not->toContain($foreign->uuid);
    // The binding is tenant-scoped, so the foreign draft is not even found.
    $this->getJson("/api/v1/courses/{$foreign->uuid}")->assertNotFound();

    confineTo($this->near);
    // A course id from another workspace written into the scope still lists
    // nothing of that workspace: the tenant filter stands under the confinement.
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(),
        'course_id' => $foreign->getKey(),
    ]);
    app()->forgetScopedInstances();

    Sanctum::actingAs($this->assistant->refresh());
    expect(listedCourseUuids())->toBe([$this->near->uuid]);
});
