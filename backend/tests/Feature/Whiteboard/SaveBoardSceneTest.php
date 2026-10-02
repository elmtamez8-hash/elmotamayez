<?php

declare(strict_types=1);

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 · US2 — one autosave: the version and the lock are one statement, a lost
| answer is retried without a conflict, and the document is checked before it is
| stored (contracts/api.md).
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
    $this->scope = AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(), 'course_id' => $this->course->getKey(),
    ]);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->board = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(), 'course_id' => $this->course->getKey(),
    ]);
    $this->page = BoardPage::query()->where('board_id', $this->board->getKey())->firstOrFail();
    $this->tab = (string) Str::uuid();
});

function wbSaveAs($user, $workspace): void
{
    test()->setCurrentWorkspace($workspace, $user);
    app()->forgetScopedInstances();
    Sanctum::actingAs($user);
}

/** @param array<int, array<string, mixed>> $elements @param array<int, string> $fileIds */
function wbScene(array $elements = [], array $fileIds = []): string
{
    return (string) json_encode(['v' => 1, 'elements' => $elements, 'appState' => [], 'fileIds' => $fileIds]);
}

function wbSave(string $tab, int $version, int $rev, string $scene): TestResponse
{
    return test()->putJson(
        '/api/v1/boards/'.test()->board->uuid.'/pages/'.test()->page->uuid.'/scene',
        ['tab' => $tab, 'version' => $version, 'client_rev' => $rev, 'scene' => $scene],
    );
}

function wbHold(string $tab): void
{
    test()->postJson('/api/v1/boards/'.test()->board->uuid.'/lock', ['tab' => $tab])->assertOk()->assertJsonPath('held', true);
}

it('saves the holder\'s scene and moves the version one step', function (): void {
    wbSaveAs($this->teacher, $this->workspace);
    wbHold($this->tab);
    $scene = wbScene([['id' => 'a', 'type' => 'rectangle']]);

    wbSave($this->tab, 1, 1, $scene)->assertOk()->assertExactJson(['version' => 2, 'client_rev' => 1]);

    $stored = $this->page->fresh();
    expect($stored->scene)->toBe($scene)
        ->and($stored->version)->toBe(2)
        ->and($stored->scene_bytes)->toBe(strlen($scene));
});

it('answers a stale version with the server copy, and writes nothing', function (): void {
    wbSaveAs($this->teacher, $this->workspace);
    wbHold($this->tab);
    $first = wbScene([['id' => 'a', 'type' => 'rectangle']]);
    wbSave($this->tab, 1, 1, $first)->assertOk();

    wbSave($this->tab, 1, 2, wbScene([['id' => 'b', 'type' => 'ellipse']]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'version_conflict')
        ->assertJsonPath('version', 2)
        ->assertJsonPath('scene', $first);

    expect($this->page->fresh()->scene)->toBe($first);
});

it('refuses a save without the lock as lock_lost', function (): void {
    wbSaveAs($this->teacher, $this->workspace);

    wbSave($this->tab, 1, 1, wbScene())->assertStatus(409)->assertJsonPath('code', 'lock_lost');
    expect($this->page->fresh()->version)->toBe(1);
});

it('refuses the old holder once the take-over grace has passed, even mid-save', function (): void {
    wbSaveAs($this->assistant, $this->workspace);
    wbHold($this->tab);

    wbSaveAs($this->teacher, $this->workspace);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/lock/take', ['tab' => (string) Str::uuid()])->assertStatus(202);

    // Within the grace the assistant still saves …
    wbSaveAs($this->assistant, $this->workspace);
    wbSave($this->tab, 1, 1, wbScene())->assertOk();

    // … and the grace running out BETWEEN any read and the write still refuses: the
    // lock is inside the UPDATE's own WHERE, so moving the clock as the statement
    // runs leaves nothing to race.
    $this->travel(11)->seconds();
    wbSave($this->tab, 2, 2, wbScene([['id' => 'late', 'type' => 'rectangle']]))
        ->assertStatus(409)->assertJsonPath('code', 'lock_lost');
    expect($this->page->fresh()->version)->toBe(2);
});

it('loses the race to a write that lands between the check and the update', function (): void {
    wbSaveAs($this->teacher, $this->workspace);
    wbHold($this->tab);

    $fired = false;
    DB::beforeExecuting(function (string $sql) use (&$fired): void {
        if (! $fired && str_starts_with($sql, 'UPDATE board_pages SET scene')) {
            $fired = true;
            DB::table('board_pages')->where('id', $this->page->getKey())->update(['version' => 7]);
        }
    });

    wbSave($this->tab, 1, 1, wbScene())->assertStatus(409)->assertJsonPath('code', 'version_conflict')->assertJsonPath('version', 7);
});

it('answers a retry of a save whose answer was lost, and only from the same tab', function (): void {
    wbSaveAs($this->teacher, $this->workspace);
    wbHold($this->tab);
    $scene = wbScene([['id' => 'a', 'type' => 'rectangle']]);

    wbSave($this->tab, 1, 5, $scene)->assertOk();
    wbSave($this->tab, 1, 5, $scene)->assertOk()->assertExactJson(['version' => 2, 'client_rev' => 5]);
    expect($this->page->fresh()->version)->toBe(2);

    // The same rev from ANOTHER tab is not a retry — that tab does not hold the lock.
    wbSave((string) Str::uuid(), 1, 5, $scene)->assertStatus(409)->assertJsonPath('code', 'lock_lost');
});

it('refuses what a page document may not contain', function (array $elements, array $fileIds, string $code): void {
    wbSaveAs($this->teacher, $this->workspace);
    wbHold($this->tab);

    wbSave($this->tab, 1, 1, wbScene($elements, $fileIds))->assertStatus(422)->assertJsonPath('code', $code);
    expect($this->page->fresh()->version)->toBe(1);
})->with([
    'inline file bytes' => [[['id' => 'i', 'type' => 'image', 'fileId' => 'f', 'dataURL' => 'data:image/png;base64,AA']], ['f'], 'inline_file'],
    'an embed' => [[['id' => 'e', 'type' => 'embeddable']], [], 'bad_element'],
    'a javascript link' => [[['id' => 'l', 'type' => 'text', 'link' => 'javascript:alert(1)']], [], 'bad_link'],
    'a scheme-relative link' => [[['id' => 'l', 'type' => 'text', 'link' => '//evil.example']], [], 'bad_link'],
    'a backslash host' => [[['id' => 'l', 'type' => 'text', 'link' => '/\\evil.example']], [], 'bad_link'],
    'a link with a trailing newline' => [[['id' => 'l', 'type' => 'text', 'link' => "https://ok.example\n"]], [], 'bad_link'],
    'an undeclared image' => [[['id' => 'i', 'type' => 'image', 'fileId' => 'x']], [], 'unknown_file'],
    'a bad template id' => [[], ['template:Grid:v1'], 'unknown_file'],
]);

it('accepts a template id and a ready picture of this board, and refuses any other file', function (): void {
    $asset = fn (array $state) => MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_type' => Board::class, 'owner_id' => $this->board->getKey(),
        'kind' => MediaKind::Document, 'mime_type' => 'image/png', 'original_filename' => 'a.png', ...$state,
    ]);
    $ready = $asset([]);
    $pending = $asset(['status' => MediaAssetStatus::Processing, 'ready_at' => null]);
    $foreign = $asset(['owner_id' => Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(),
    ])->getKey()]);

    wbSaveAs($this->teacher, $this->workspace);
    wbHold($this->tab);

    wbSave($this->tab, 1, 1, wbScene([['id' => 'i', 'type' => 'image', 'fileId' => (string) $ready->uuid]], [(string) $ready->uuid, 'template:grid:v1', 'template:sticker-excellent:v1']))->assertOk();
    wbSave($this->tab, 2, 2, wbScene([], [(string) $pending->uuid]))->assertStatus(422)->assertJsonPath('code', 'unknown_file');
    wbSave($this->tab, 2, 3, wbScene([], [(string) $foreign->uuid]))->assertStatus(422)->assertJsonPath('code', 'unknown_file');
});

it('refuses a page that would take the board past its total', function (): void {
    PlatformSettings::set('whiteboard.max_board_bytes', 400);
    BoardPage::query()->where('id', $this->page->getKey())->update(['scene_bytes' => 10]);
    $other = BoardPage::factory()->create(['board_id' => $this->board->getKey(), 'workspace_id' => $this->workspace->getKey(), 'scene_bytes' => 350, 'position' => 2]);

    wbSaveAs($this->teacher, $this->workspace);
    wbHold($this->tab);

    wbSave($this->tab, 1, 1, wbScene([['id' => str_repeat('x', 60), 'type' => 'rectangle']]))
        ->assertStatus(422)->assertJsonPath('code', 'board_too_large');
    expect($other->exists)->toBeTrue();
});

it('lets the manager hold no lock, and the assistant take none', function (): void {
    wbSaveAs($this->owner, $this->workspace);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/lock', ['tab' => $this->tab])->assertForbidden();

    wbSaveAs($this->assistant, $this->workspace);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/lock/take', ['tab' => $this->tab])->assertForbidden();
});

it('stops an assistant at the next save once their scope is taken away', function (): void {
    wbSaveAs($this->assistant, $this->workspace);
    wbHold($this->tab);
    wbSave($this->tab, 1, 1, wbScene())->assertOk();

    $this->scope->delete();
    $other = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    AssistantScope::factory()->create(['assistant_assignment_id' => $this->assignment->getKey(), 'course_id' => $other->getKey()]);
    wbSaveAs($this->assistant, $this->workspace);

    // The board is no longer theirs to see, so the answer is the not-found one.
    wbSave($this->tab, 2, 2, wbScene())->assertNotFound();
    expect($this->page->fresh()->version)->toBe(2);
});

it('refuses a client_rev its unsigned column cannot hold — MySQL would answer 500', function (): void {
    wbSaveAs($this->teacher, $this->workspace);
    wbHold($this->tab);

    wbSave($this->tab, 1, 4294967296, wbScene())->assertUnprocessable()->assertJsonValidationErrors('client_rev');
});
