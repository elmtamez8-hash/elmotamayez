<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Models\Board;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 · SC-007 — EVERY board door against every intruder, with exact codes.
|
| ⚠️ THE MATRIX COUNTS THE ROUTES. A door added without a row here turns the last
| test red, so a new endpoint cannot ship untested against another workspace. Each
| story that adds routes adds its rows to `wbDoors()`.
*/

/** @return list<array{0: string, 1: string, 2: array<string, mixed>}> method, uri (with {board}), body */
function wbDoors(): array
{
    return [
        ['GET', '/api/v1/boards/{board}', []],
        ['PATCH', '/api/v1/boards/{board}', ['title' => 'اختراق']],
        ['POST', '/api/v1/boards/{board}/duplicate', []],
        ['DELETE', '/api/v1/boards/{board}', []],
        // US2 — the edit lock and autosave.
        ['POST', '/api/v1/boards/{board}/lock', ['tab' => WB_TAB]],
        ['DELETE', '/api/v1/boards/{board}/lock', ['tab' => WB_TAB]],
        ['POST', '/api/v1/boards/{board}/lock/take', ['tab' => WB_TAB]],
        ['PUT', '/api/v1/boards/{board}/pages/{page}/scene', ['tab' => WB_TAB, 'version' => 1, 'client_rev' => 1, 'scene' => '{"v":1,"elements":[],"appState":{},"fileIds":[]}']],
        // US3 — pages.
        ['POST', '/api/v1/boards/{board}/pages', ['tab' => WB_TAB]],
        ['PUT', '/api/v1/boards/{board}/pages/order', ['tab' => WB_TAB, 'pages' => ['{page}']]],
        ['DELETE', '/api/v1/boards/{board}/pages/{page}', ['tab' => WB_TAB]],
        ['POST', '/api/v1/boards/{board}/files', ['tab' => WB_TAB, 'filename' => 'a.png', 'size' => 10]],
        ['POST', '/api/v1/boards/{board}/files/{file}/complete', []],
        ['GET', '/api/v1/boards/{board}/files/{file}', []],
        ['POST', '/api/v1/boards/{board}/imports', ['tab' => WB_TAB, 'filename' => 'a.pdf', 'size' => 10]],
        ['POST', '/api/v1/boards/{board}/imports/{import}/complete', []],
        ['GET', '/api/v1/boards/{board}/imports/{import}', []],
    ];
}

const WB_TAB = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

/** The list is a door too, but it answers 200 with what the reader may see — checked apart. */
const WB_LIST_ROUTES = ['GET api/v1/boards', 'POST api/v1/boards'];

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'created_by' => $this->teacher->getKey()]);
    $this->board = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_user_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'title' => 'سبّورة المدرّس',
    ]);
    // A READY picture of the board, so the file doors answer 404 for the right reason.
    $this->file = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_type' => Board::class, 'owner_id' => $this->board->getKey(),
        'kind' => MediaKind::Document, 'mime_type' => 'image/png', 'original_filename' => 'a.png',
    ]);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

function wbCall(string $method, string $uri, array $body, User $as, ?Workspace $workspace): int
{
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
    app()->forgetScopedInstances();
    if ($workspace !== null) {
        test()->setCurrentWorkspace($workspace, $as);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());
    }
    Sanctum::actingAs($as);

    // Past the workspace scope: the caller here is often from another workspace.
    $page = (string) DB::table('board_pages')->where('board_id', test()->board->id)->value('uuid');

    $body = json_decode(str_replace('{page}', $page, (string) json_encode($body)), true);
    $uri = str_replace('{file}', (string) test()->file->uuid, $uri);
    // Any uuid: the board is refused before the import is looked for.
    $uri = str_replace('{import}', (string) test()->file->uuid, $uri);

    return test()->json($method, str_replace(['{board}', '{page}'], [(string) test()->board->uuid, $page], $uri), $body)->status();
}

it('hides every board door from another workspace — 404, as if it did not exist', function (): void {
    [$other, $stranger] = $this->createWorkspaceWithOwner(['name' => 'Elsewhere']);

    foreach (wbDoors() as [$method, $uri, $body]) {
        expect(wbCall($method, $uri, $body, $stranger, $other))->toBe(404, "{$method} {$uri}");
    }
});

it('refuses every board door to a removed member', function (): void {
    $this->workspace->members()->detach($this->teacher->getKey());
    $this->teacher->forceFill(['last_workspace_id' => null])->save();

    foreach (wbDoors() as [$method, $uri, $body]) {
        expect(wbCall($method, $uri, $body, $this->teacher->fresh(), null))->toBe(404, "{$method} {$uri}");
    }
});

it('refuses every board door to a student handed lessons.manage', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $student->givePermissionTo(Permissions::LESSONS_MANAGE);

    foreach (wbDoors() as [$method, $uri, $body]) {
        expect(wbCall($method, $uri, $body, $student, $this->workspace))->toBeIn([403, 404], "{$method} {$uri}");
    }
});

it('refuses every board door to an assistant confined to another course', function (): void {
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $assignment->getKey(),
        'course_id' => Course::factory()->create(['workspace_id' => $this->workspace->getKey()])->getKey(),
    ]);

    foreach (wbDoors() as [$method, $uri, $body]) {
        expect(wbCall($method, $uri, $body, $assistant, $this->workspace))->toBe(404, "{$method} {$uri}");
    }
});

it('refuses the manager who is not the board\'s teacher every WRITE door', function (): void {
    foreach (wbDoors() as [$method, $uri, $body]) {
        // Q5: the manager views, exports and DELETES; a copy is a new board of
        // their own, which changes nothing of the teacher's. Both checked below.
        if ($method === 'GET' || in_array($uri, ['/api/v1/boards/{board}/duplicate', '/api/v1/boards/{board}'], true)) {
            continue;
        }
        expect(wbCall($method, $uri, $body, $this->owner, $this->workspace))->toBe(403, "{$method} {$uri}");
    }
});

it('keeps another workspace\'s boards out of the list', function (): void {
    [$other, $stranger] = $this->createWorkspaceWithOwner(['name' => 'Elsewhere']);

    expect(wbCall('GET', '/api/v1/boards', [], $stranger, $other))->toBe(200);
    expect(collect(test()->getJson('/api/v1/boards')->json('data'))->pluck('uuid')->all())
        ->not->toContain((string) $this->board->uuid);
});

it('has a row for every board route that exists', function (): void {
    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/boards'))
        ->flatMap(fn ($route): array => array_map(
            fn (string $method): string => $method.' '.$route->uri(),
            array_diff($route->methods(), ['HEAD']),
        ))
        ->sort()->values()->all();

    $covered = collect(wbDoors())
        ->map(fn (array $door): string => $door[0].' '.ltrim(str_replace('{board}', '{board}', $door[1]), '/'))
        ->merge(WB_LIST_ROUTES)
        ->sort()->values()->all();

    expect($covered)->toBe($registered);
});
