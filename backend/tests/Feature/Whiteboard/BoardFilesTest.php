<?php

declare(strict_types=1);

use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Models\Board;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 · US3 — a board's pictures: reserved by the lock holder, settled on the
| bytes, and read back only when READY, an image, and this board's.
*/

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());
    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->board = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(),
    ]);
    $this->tab = (string) Str::uuid();

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    app()->forgetScopedInstances();
    Sanctum::actingAs($this->teacher);
});

function wbPicture(Board $board, array $state = []): MediaAsset
{
    $path = 'media/'.Str::uuid().'.png';
    Storage::disk((string) config('media.disk'))->put($path, 'png-bytes');

    return MediaAsset::factory()->create([
        'workspace_id' => $board->workspace_id, 'owner_type' => Board::class, 'owner_id' => $board->getKey(),
        'kind' => MediaKind::Document, 'mime_type' => 'image/png', 'original_filename' => 'a.png',
        'provider_asset_id' => $path, ...$state,
    ]);
}

it('serves a ready picture of this board, private and never sniffed', function (): void {
    $file = wbPicture($this->board);

    $response = $this->get('/api/v1/boards/'.$this->board->uuid.'/files/'.$file->uuid)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('max-age=86400');
});

it('answers 404 for another board\'s picture, an upload still pending, and an imported PDF', function (): void {
    $other = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(),
    ]);
    $files = [
        wbPicture($other),
        wbPicture($this->board, ['status' => MediaAssetStatus::Pending, 'ready_at' => null]),
        wbPicture($this->board, ['mime_type' => 'application/pdf']),
    ];

    foreach ($files as $file) {
        $this->getJson('/api/v1/boards/'.$this->board->uuid.'/files/'.$file->uuid)->assertNotFound();
    }
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/files/'.$files[0]->uuid.'/complete')->assertNotFound();
});

it('reserves an upload for the lock holder only', function (): void {
    $url = '/api/v1/boards/'.$this->board->uuid.'/files';
    $body = ['tab' => $this->tab, 'filename' => 'photo.png', 'size' => 2048];

    $this->postJson($url, $body)->assertStatus(409)->assertJsonPath('code', 'lock_lost');

    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/lock', ['tab' => $this->tab])->assertOk();
    $reserved = $this->postJson($url, $body)->assertCreated();

    expect(MediaAsset::query()->withoutWorkspaceScope()->where('uuid', $reserved->json('file.uuid'))->first())
        ->owner_type->toBe(Board::class)
        ->owner_id->toBe($this->board->getKey())
        ->status->toBe(MediaAssetStatus::Pending);
});
