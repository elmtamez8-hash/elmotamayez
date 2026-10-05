<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Actions\ShareLibraryItem;
use App\Modules\Whiteboard\Models\BoardLibraryItem;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| The academy's shared board library (owner decisions 2026-10-05): any teacher
| shares and sees; the sharer or the academy's owner removes; nobody outside
| the workspace sees a thing.
*/

const WBLIB_SHAPE = [['id' => 'a', 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 50]];

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->colleague = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->item = BoardLibraryItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by_user_id' => $this->teacher->getKey(),
    ]);
});

function wblibAs(User $user, ?Workspace $workspace): void
{
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
    app()->forgetScopedInstances();
    if ($workspace !== null) {
        test()->setCurrentWorkspace($workspace, $user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());
    }
    Sanctum::actingAs($user);
}

it('lets a teacher share a shape that every teacher of the academy then sees', function (): void {
    wblibAs($this->colleague, $this->workspace);
    $this->postJson('/api/v1/board-library', ['name' => 'خلية نباتية', 'elements' => WBLIB_SHAPE])
        ->assertCreated()
        ->assertJsonPath('name', 'خلية نباتية')
        ->assertJsonPath('elements.0.type', 'rectangle')
        ->assertJsonPath('shared_by', $this->colleague->name)
        ->assertJsonPath('can_delete', true);

    wblibAs($this->teacher, $this->workspace);
    $list = $this->getJson('/api/v1/board-library')->assertOk();
    expect(collect($list->json('data'))->pluck('name')->all())->toBe(['خلية نباتية', 'مثلث قائم'])
        // Another teacher's shape: seen, not removable.
        ->and($list->json('data.0.can_delete'))->toBeFalse()
        ->and($list->json('data.1.can_delete'))->toBeTrue();
});

it('lets the sharer and the academy owner remove a shape, and nobody else', function (): void {
    wblibAs($this->colleague, $this->workspace);
    $this->deleteJson('/api/v1/board-library/'.$this->item->uuid)->assertForbidden();

    wblibAs($this->teacher, $this->workspace);
    $this->deleteJson('/api/v1/board-library/'.$this->item->uuid)->assertNoContent();

    $other = BoardLibraryItem::factory()->create(['workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->colleague->getKey()]);
    wblibAs($this->owner, $this->workspace);
    $this->deleteJson('/api/v1/board-library/'.$other->uuid)->assertNoContent();

    expect(BoardLibraryItem::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('keeps the library from a learner and from another workspace — its shapes do not exist there', function (): void {
    wblibAs($this->student, $this->workspace);
    $this->getJson('/api/v1/board-library')->assertForbidden();
    $this->postJson('/api/v1/board-library', ['name' => 'x', 'elements' => WBLIB_SHAPE])->assertForbidden();

    [$elsewhere, $stranger] = $this->createWorkspaceWithOwner();
    wblibAs($stranger, $elsewhere);
    expect($this->getJson('/api/v1/board-library')->assertOk()->json('data'))->toBe([]);
    $this->deleteJson('/api/v1/board-library/'.$this->item->uuid)->assertNotFound();
    expect(BoardLibraryItem::query()->withoutWorkspaceScope()->whereKey($this->item->id)->exists())->toBeTrue();
});

it('refuses what a page refuses: an embed, inline bytes, an uploaded picture, a script link', function (array $element, string $code): void {
    wblibAs($this->teacher, $this->workspace);
    $this->postJson('/api/v1/board-library', ['name' => 'x', 'elements' => [$element]])
        ->assertStatus(422)
        ->assertJsonPath('code', $code);
})->with([
    'embed' => [['type' => 'embeddable'], 'bad_element'],
    'inline bytes' => [['type' => 'image', 'fileId' => 'template:grid:v1', 'dataURL' => 'data:image/png;base64,AA'], 'inline_file'],
    'uploaded picture' => [['type' => 'image', 'fileId' => '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'], 'unknown_file'],
    'script link' => [['type' => 'rectangle', 'link' => 'javascript:alert(1)'], 'bad_link'],
]);

it('keeps a template picture, which draws on any board', function (): void {
    wblibAs($this->teacher, $this->workspace);
    $this->postJson('/api/v1/board-library', ['name' => 'ورق رسم بياني', 'elements' => [['type' => 'image', 'fileId' => 'template:graph:v1']]])
        ->assertCreated();
});

it('refuses a shape too large to send to every board, and an academy library that is full', function (): void {
    wblibAs($this->teacher, $this->workspace);
    $huge = [['type' => 'text', 'text' => str_repeat('ب', ShareLibraryItem::MAX_BYTES)]];
    $this->postJson('/api/v1/board-library', ['name' => 'x', 'elements' => $huge])->assertStatus(422)->assertJsonPath('code', 'library_item_too_large');

    DB::table('board_library_items')->insert(array_map(fn (int $i): array => [
        'uuid' => (string) Str::uuid(), 'workspace_id' => $this->workspace->getKey(), 'name' => "شكل {$i}", 'elements' => '[]',
        'created_at' => now(), 'updated_at' => now(),
    ], range(1, ShareLibraryItem::MAX_ITEMS)));
    $this->postJson('/api/v1/board-library', ['name' => 'x', 'elements' => WBLIB_SHAPE])->assertStatus(409)->assertJsonPath('code', 'library_full');
});
