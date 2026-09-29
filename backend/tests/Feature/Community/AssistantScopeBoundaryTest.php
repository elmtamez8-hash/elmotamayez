<?php

declare(strict_types=1);

use App\Modules\Community\Actions\SetAssistantScope;
use App\Modules\Community\Data\AssistantScopeData;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| The team screen's three reads and one write stay inside the owner's workspace.
|
| ⚠️ TWO WORKSPACES IN EVERY FIXTURE. One workspace is what hid the offboarding
| defect in `docs/gotchas/compliance.md`: with a single tenant «scoped» and
| «unscoped» return the same rows, and every assertion here would be green
| against a product with no boundary at all.
|
| ⚠️ AND THE FORGED UUID IS REFUSED AT BOTH DOORS. The Form Request is the HTTP
| door; the Action is the one a seeder and the panel reach with no request at
| all, and until this file it DROPPED an unresolved uuid in silence — which, for
| a set made only of foreign courses, wrote `[]`: «no confinement», the widening
| direction.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    [$this->other, $this->otherOwner] = $this->createWorkspaceWithOwner();

    // The other workspace is built FIRST: `addWorkspaceMember()` moves the
    // context to the workspace it adds to, so building it last would leave the
    // owner's requests resolving someone else's workspace.
    $this->theirs = Course::factory()->create([
        'workspace_id' => $this->other->getKey(),
        'created_by' => $this->otherOwner->getKey(),
        'title' => 'كورس مساحة أخرى',
    ]);

    $otherAssistant = $this->addWorkspaceMember($this->other, Roles::ASSISTANT_TEACHER);

    $this->foreignAssignment = AssistantAssignment::factory()->create([
        'workspace_id' => $this->other->getKey(),
        'assistant_user_id' => $otherAssistant->getKey(),
        'invited_by_user_id' => $this->otherOwner->getKey(),
    ]);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->mine = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
        'title' => 'الرياضيات',
        'status' => 'published',
    ]);
    $this->draft = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
        'title' => 'الفيزياء',
        'status' => 'draft',
    ]);

    $this->assignment = AssistantAssignment::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

it('offers the picker this workspace\'s live courses only, with status and cover', function (): void {
    $deleted = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'title' => 'محذوف']);
    $deleted->delete();

    Sanctum::actingAs($this->owner);

    $response = $this->getJson('/api/v1/manage/assistants/courses')->assertOk();

    expect(collect($response->json('data'))->pluck('uuid')->all())
        ->toEqualCanonicalizing([$this->mine->uuid, $this->draft->uuid])
        ->and($response->json('data.0'))->toHaveKeys(['uuid', 'title', 'cover_url', 'status', 'teacher'])
        ->and($response->json('data.0'))->not->toHaveKey('price_minor')
        ->and($response->json('meta.teachers_count'))->toBe(1);
});

it('lists this workspace\'s team only, and each course with its details', function (): void {
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(),
        'course_id' => $this->mine->getKey(),
    ]);

    Sanctum::actingAs($this->owner);

    $response = $this->getJson('/api/v1/manage/assistants')->assertOk();

    expect(collect($response->json('data'))->pluck('uuid')->all())->toBe([$this->assignment->uuid])
        ->and($response->json('data.0.courses.0'))->toMatchArray([
            'uuid' => $this->mine->uuid,
            'status' => 'published',
            'teacher' => ['name' => $this->owner->name],
        ])
        ->and($response->json('data.0.unavailable_courses_count'))->toBe(0);
});

it('counts a scoped course that was deleted instead of dropping it in silence', function (): void {
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(),
        'course_id' => $this->draft->getKey(),
    ]);
    $this->draft->delete();

    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/manage/assistants')
        ->assertOk()
        ->assertJsonPath('data.0.is_confined', true)
        ->assertJsonPath('data.0.courses', [])
        ->assertJsonPath('data.0.unavailable_courses_count', 1);
});

it('refuses a forged course uuid from another workspace and writes nothing', function (): void {
    Sanctum::actingAs($this->owner);

    $this->putJson("/api/v1/manage/assistants/{$this->assignment->uuid}/scope", [
        'courses' => [$this->theirs->uuid],
    ])->assertUnprocessable()->assertJsonValidationErrors('courses.0');

    expect(AssistantScope::query()->count())->toBe(0);
});

it('refuses a deleted course uuid rather than widening to every course', function (): void {
    $this->draft->delete();

    Sanctum::actingAs($this->owner);

    $this->putJson("/api/v1/manage/assistants/{$this->assignment->uuid}/scope", [
        'courses' => [$this->draft->uuid],
    ])->assertUnprocessable()->assertJsonValidationErrors('courses.0');
});

it('saves a scope made of this workspace\'s courses', function (): void {
    Sanctum::actingAs($this->owner);

    $this->putJson("/api/v1/manage/assistants/{$this->assignment->uuid}/scope", [
        'courses' => [$this->mine->uuid, $this->draft->uuid],
    ])->assertOk()->assertJsonPath('is_confined', true)->assertJsonCount(2, 'courses');
});

it('answers 404 for another workspace\'s assignment', function (): void {
    Sanctum::actingAs($this->owner);

    $this->putJson("/api/v1/manage/assistants/{$this->foreignAssignment->uuid}/scope", [
        'courses' => [],
    ])->assertNotFound();
});

it('refuses to scope an assistant whose assignment was withdrawn', function (): void {
    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/v1/manage/assistants/{$this->assignment->uuid}")->assertNoContent();

    $this->putJson("/api/v1/manage/assistants/{$this->assignment->uuid}/scope", [
        'courses' => [$this->mine->uuid],
    ])->assertUnprocessable()->assertJsonValidationErrors('assignment');

    expect(AssistantScope::query()->count())->toBe(0);
});

it('refuses a foreign course at the Action too, where no Form Request runs', function (): void {
    // A set made ONLY of foreign courses resolved to `[]` — «every course».
    expect(fn () => app(SetAssistantScope::class)->handle(
        $this->assignment,
        new AssistantScopeData([$this->theirs->uuid]),
    ))->toThrow(ValidationException::class);

    expect(AssistantScope::query()->count())->toBe(0);
});

it('names the teacher of each course in an academy', function (): void {
    $this->addWorkspaceMember($this->workspace, Roles::TEACHER);

    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/manage/assistants/courses')->assertJsonPath('meta.teachers_count', 2);
});

it('lists the team in a constant number of queries, however many courses each holds', function (): void {
    // One scoped course in the baseline: with none, the `scopes.course` and
    // `creator` eager loads have no keys and issue no query, so the jump from
    // zero to one would read as an N+1 that is not there.
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(),
        'course_id' => $this->mine->getKey(),
    ]);

    Sanctum::actingAs($this->owner);

    $measure = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/manage/assistants')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // Warm until steady (docs/gotchas/testing.md): the first requests pay for
    // caches that later ones do not.
    $measure();
    $measure();
    $before = $measure();

    foreach (range(1, 3) as $n) {
        $member = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
        $assignment = AssistantAssignment::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'assistant_user_id' => $member->getKey(),
            'invited_by_user_id' => $this->owner->getKey(),
        ]);
        $teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);

        foreach (range(1, 2) as $m) {
            $course = Course::factory()->create([
                'workspace_id' => $this->workspace->getKey(),
                'created_by' => $teacher->getKey(),
            ]);
            AssistantScope::factory()->create([
                'assistant_assignment_id' => $assignment->getKey(),
                'course_id' => $course->getKey(),
            ]);
        }
    }

    expect($measure())->toBe($before);
});
