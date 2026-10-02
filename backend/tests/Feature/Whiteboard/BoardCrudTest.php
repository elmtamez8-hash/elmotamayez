<?php

declare(strict_types=1);

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Models\Board;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 · US1 — create, list, open and rename a board over HTTP.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'created_by' => $this->teacher->getKey()]);
    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

function wbActAs($user, $workspace): void
{
    test()->setCurrentWorkspace($workspace, $user);
    app()->forgetScopedInstances();
    Sanctum::actingAs($user);
}

it('creates a board with its first blank page, and opens it', function (): void {
    wbActAs($this->teacher, $this->workspace);

    $created = $this->postJson('/api/v1/boards', ['title' => 'مراجعة الكيمياء', 'course' => $this->course->uuid])
        ->assertCreated()
        ->assertJsonPath('title', 'مراجعة الكيمياء')
        ->assertJsonPath('pages_count', 1)
        ->assertJsonPath('course.uuid', $this->course->uuid)
        ->assertJsonPath('teacher.uuid', $this->teacher->uuid)
        ->assertJsonPath('can.edit', true);

    $opened = $this->getJson('/api/v1/boards/'.$created->json('uuid'))->assertOk();
    $page = $opened->json('pages.0');
    $scene = json_decode($page['scene'], true);

    expect($opened->json('pages'))->toHaveCount(1)
        ->and($page['version'])->toBe(1)
        // The scene goes out as the stored TEXT — the browser parses it.
        ->and($page['scene'])->toBeString()
        ->and($scene['elements'][0]['id'])->toBe('frame:'.$page['uuid'])
        ->and($scene['elements'][0]['type'])->toBe('frame');
});

it('derives the course from a lesson, and refuses a lesson from another course', function (): void {
    wbActAs($this->teacher, $this->workspace);
    $lesson = Lesson::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $lessonCourse = Course::query()->find($lesson->course_id);

    $this->postJson('/api/v1/boards', ['title' => 'درس', 'lesson' => $lesson->uuid])
        ->assertCreated()
        ->assertJsonPath('course.uuid', (string) $lessonCourse?->uuid)
        ->assertJsonPath('lesson.uuid', (string) $lesson->uuid);

    $this->postJson('/api/v1/boards', ['title' => 'درس', 'course' => $this->course->uuid, 'lesson' => $lesson->uuid])
        ->assertUnprocessable();
});

it('refuses a confined assistant a course-less board and a course outside the scope', function (): void {
    $inside = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    AssistantScope::factory()->create(['assistant_assignment_id' => $this->assignment->getKey(), 'course_id' => $inside->getKey()]);
    wbActAs($this->assistant, $this->workspace);

    $this->postJson('/api/v1/boards', ['title' => 'بلا كورس'])->assertUnprocessable();
    $this->postJson('/api/v1/boards', ['title' => 'خارج النطاق', 'course' => $this->course->uuid])->assertUnprocessable();
    $this->postJson('/api/v1/boards', ['title' => 'داخل النطاق', 'course' => $inside->uuid])->assertCreated();
});

it('lists to a confined assistant only their own boards and their scope\'s — and the total agrees', function (): void {
    $inside = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    AssistantScope::factory()->create(['assistant_assignment_id' => $this->assignment->getKey(), 'course_id' => $inside->getKey()]);

    $make = fn ($creator, $course, $title) => Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $creator->getKey(),
        'course_id' => $course?->getKey(), 'title' => $title,
    ]);
    $make($this->teacher, $inside, 'في النطاق');
    $make($this->teacher, $this->course, 'خارج النطاق');
    $make($this->teacher, null, 'بلا كورس لزميل');

    wbActAs($this->assistant, $this->workspace);
    $response = $this->getJson('/api/v1/boards')->assertOk();

    expect(array_column($response->json('data'), 'title'))->toBe(['في النطاق'])
        ->and($response->json('meta.total'))->toBe(1);
});

it('answers a board of another workspace exactly like a board that does not exist', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'Elsewhere']);
    $foreign = app(WorkspaceContext::class)->forWorkspace($other, fn () => Board::factory()->withPages(1)->create([
        'workspace_id' => $other->getKey(), 'owner_user_id' => $otherOwner->getKey(),
    ]));

    wbActAs($this->owner, $this->workspace);

    $missingUuid = fake()->uuid();
    $missing = $this->getJson('/api/v1/boards/'.$missingUuid)->assertNotFound();
    $hidden = $this->getJson('/api/v1/boards/'.$foreign->uuid)->assertNotFound();

    // The same answer, word for word, once each uuid is taken out of its own message.
    expect(str_replace((string) $foreign->uuid, '{uuid}', (string) $hidden->json('message')))
        ->toBe(str_replace($missingUuid, '{uuid}', (string) $missing->json('message')));
});

it('lets only the owning teacher move a board, and never into a state they could not edit', function (): void {
    $board = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->assistant->getKey(), 'course_id' => $this->course->getKey(),
    ]);

    // The assistant edits it (a course author) but may not clear the course and so
    // make themselves its owner.
    wbActAs($this->assistant, $this->workspace);
    $this->patchJson('/api/v1/boards/'.$board->uuid, ['title' => 'عنوان جديد'])->assertOk();
    $this->patchJson('/api/v1/boards/'.$board->uuid, ['course' => null])->assertForbidden();
    expect($board->fresh()->course_id)->toBe($this->course->getKey());

    // The course's teacher may move it to another of their courses …
    $another = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'created_by' => $this->teacher->getKey()]);
    wbActAs($this->teacher, $this->workspace);
    $this->patchJson('/api/v1/boards/'.$board->uuid, ['course' => $another->uuid])->assertOk()->assertJsonPath('course.uuid', (string) $another->uuid);

    // … but not out of every course: a course-less board belongs to its creator (D1),
    // the assistant, and the teacher would lose it — refused before the commit.
    $this->patchJson('/api/v1/boards/'.$board->uuid, ['course' => null])->assertForbidden();
    expect($board->fresh()->course_id)->toBe($another->getKey());
});

it('reports the same «can» the policy answers', function (): void {
    $board = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(), 'course_id' => $this->course->getKey(),
    ]);

    foreach ([$this->owner, $this->teacher, $this->assistant] as $reader) {
        wbActAs($reader, $this->workspace);
        $can = $this->getJson('/api/v1/boards/'.$board->uuid)->assertOk()->json('can');

        expect($can)->toBe([
            'edit' => Gate::forUser($reader)->allows('update', $board),
            'take_lock' => Gate::forUser($reader)->allows('takeLock', $board),
            'export' => Gate::forUser($reader)->allows('export', $board),
            'delete' => Gate::forUser($reader)->allows('delete', $board),
        ]);
    }
});

it('lists twenty boards in a steady number of queries, names included', function (): void {
    foreach (range(1, 20) as $i) {
        Board::factory()->withPages(1)->create([
            'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(),
            'course_id' => $this->course->getKey(), 'title' => "سبّورة {$i}",
        ]);
    }
    wbActAs($this->teacher, $this->workspace);

    // Warm until steady (gotchas/testing.md), then measure.
    $this->getJson('/api/v1/boards')->assertOk();
    $this->getJson('/api/v1/boards')->assertOk();
    [$queries, $response] = countingQueries(fn () => $this->getJson('/api/v1/boards')->assertOk());

    $first = $response->json('data.0');
    expect($queries)->toBeLessThanOrEqual(20)
        ->and($first['owner']['name'])->not->toBe('')
        ->and($first['teacher']['uuid'])->toBe($this->teacher->uuid)
        ->and($first['course']['title'])->toBe($this->course->title);
});
