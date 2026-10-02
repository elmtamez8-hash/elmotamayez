<?php

declare(strict_types=1);

use App\Modules\Media\Actions\CopyLocalMediaAsset;
use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Modules\Whiteboard\Jobs\DeleteBoardJob;
use App\Modules\Whiteboard\Jobs\DuplicateBoardJob;
use App\Modules\Whiteboard\Jobs\SweepWhiteboardJob;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 · US3 — copying and deleting a whole board, in the queue. The copy owns
| its own files; a double click is a 409; a crash is finished by the sweep.
| (The queue is `sync` here, so the jobs run as they are dispatched.)
*/

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());
    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->board = Board::factory()->withPages(2)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(), 'title' => 'الكيمياء',
    ]);

    // A picture on the first page, referenced by its scene and as its background.
    $path = 'media/'.Str::uuid();
    Storage::disk((string) config('media.disk'))->put($path, 'png-bytes');
    $this->picture = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_type' => Board::class, 'owner_id' => $this->board->getKey(),
        'kind' => MediaKind::Document, 'mime_type' => 'image/png', 'original_filename' => 'a.png', 'provider_asset_id' => $path,
    ]);
    $page = BoardPage::query()->withoutWorkspaceScope()->where('board_id', $this->board->id)->orderBy('position')->firstOrFail();
    $doc = json_decode($page->scene, true);
    $doc['elements'][] = ['id' => 'img', 'type' => 'image', 'fileId' => (string) $this->picture->uuid];
    $doc['fileIds'] = [(string) $this->picture->uuid];
    $page->forceFill(['scene' => json_encode($doc), 'background_asset_id' => $this->picture->id])->save();

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    app()->forgetScopedInstances();
    Sanctum::actingAs($this->teacher);
});

function wbCopyOf(Board $original): Board
{
    return Board::query()->withoutWorkspaceScope()->where('id', '<>', $original->id)->latest('id')->firstOrFail();
}

it('copies every page and picture, and the copy shares no file with the original (US3-3)', function (): void {
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/duplicate')->assertStatus(202)->assertJsonPath('status', 'copying');

    $copy = wbCopyOf($this->board);
    $pages = BoardPage::query()->withoutWorkspaceScope()->where('board_id', $copy->id)->orderBy('position')->get();
    $copiedPicture = MediaAsset::query()->withoutWorkspaceScope()->where('owner_type', Board::class)->where('owner_id', $copy->id)->sole();
    $doc = json_decode($pages[0]->scene, true);

    expect($copy->pending_operation)->toBeNull()
        ->and($copy->title)->toBe('الكيمياء (نسخة)')
        ->and($copy->pages_count)->toBe(2)
        ->and($pages)->toHaveCount(2)
        ->and($doc['elements'][0]['id'])->toBe('frame:'.$pages[0]->uuid)
        ->and($doc['fileIds'])->toBe([(string) $copiedPicture->uuid])
        ->and($pages[0]->scene)->not->toContain((string) $this->picture->uuid)
        ->and($pages[0]->background_asset_id)->toBe($copiedPicture->id)
        ->and($this->board->fresh()->pending_operation)->toBeNull();

    // Deleting the original leaves the copy's picture on disk.
    DB::update('UPDATE boards SET pending_operation = ? WHERE id = ?', ['deleting', $this->board->id]);
    (new DeleteBoardJob($this->board->id))->handle(app(DeleteMediaAsset::class));

    expect(Board::query()->withoutWorkspaceScope()->find($this->board->id))->toBeNull()
        ->and(Storage::disk((string) config('media.disk'))->exists((string) $copiedPicture->provider_asset_id))->toBeTrue()
        ->and(Storage::disk((string) config('media.disk'))->exists((string) $this->picture->provider_asset_id))->toBeFalse();
});

it('answers a second copy while the first is running with 409, even in the gap', function (): void {
    Queue::fake([DuplicateBoardJob::class]);

    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/duplicate')->assertStatus(202);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/duplicate')->assertStatus(409)->assertJsonPath('code', 'operation_pending');
    Queue::assertPushed(DuplicateBoardJob::class, 1);

    // The hidden copy is in no list and opens for nobody.
    $copy = wbCopyOf($this->board);
    expect($copy->pending_operation)->toBe(BoardPendingOperation::Building);
    expect(collect($this->getJson('/api/v1/boards')->json('data'))->pluck('uuid'))->not->toContain((string) $copy->uuid);
    $this->getJson('/api/v1/boards/'.$copy->uuid)->assertNotFound();
});

it('releases the original and removes the half-built copy when the copy job fails', function (): void {
    Queue::fake([DuplicateBoardJob::class]);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/duplicate')->assertStatus(202);
    $copy = wbCopyOf($this->board);

    Queue::fake();
    (new DuplicateBoardJob($this->board->id, $copy->id))->failed(new RuntimeException('disk full'));

    expect($this->board->fresh()->pending_operation)->toBeNull()
        ->and($copy->fresh()->pending_operation)->toBe(BoardPendingOperation::Deleting);
    Queue::assertPushed(DeleteBoardJob::class);
});

it('deletes a board at once from every list, and a second delete job is harmless', function (): void {
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/lock', ['tab' => (string) Str::uuid()])->assertOk();
    Queue::fake([DeleteBoardJob::class]);

    $this->deleteJson('/api/v1/boards/'.$this->board->uuid)->assertStatus(202);
    $this->getJson('/api/v1/boards/'.$this->board->uuid)->assertNotFound();
    $this->deleteJson('/api/v1/boards/'.$this->board->uuid)->assertNotFound();

    $job = new DeleteBoardJob($this->board->id);
    $job->handle(app(DeleteMediaAsset::class));
    $job->handle(app(DeleteMediaAsset::class));

    expect(Board::query()->withoutWorkspaceScope()->find($this->board->id))->toBeNull()
        ->and(MediaAsset::query()->withoutWorkspaceScope()->where('owner_type', Board::class)->where('owner_id', $this->board->id)->count())->toBe(0);
});

it('refuses a deletion to a teacher past their two-factor deadline', function (): void {
    $this->teacher->securitySettings()->updateOrCreate([], ['two_factor_required_at' => CarbonImmutable::now()->subDay()]);

    $this->deleteJson('/api/v1/boards/'.$this->board->uuid)->assertForbidden();
    expect($this->board->fresh()->pending_operation)->toBeNull();
});

it('sweeps what a dead job left: a stuck deletion is sent again, a stuck copy removed', function (): void {
    $old = CarbonImmutable::now()->subMinutes(SweepWhiteboardJob::STUCK_AFTER_MINUTES + 1)->format('Y-m-d H:i:s');
    $stuckCopy = Board::factory()->withPages(1)->create(['workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey()]);
    $fresh = Board::factory()->withPages(1)->create(['workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey()]);
    DB::update('UPDATE boards SET pending_operation = ?, updated_at = ? WHERE id = ?', ['deleting', $old, $this->board->id]);
    DB::update('UPDATE boards SET pending_operation = ?, updated_at = ? WHERE id = ?', ['building', $old, $stuckCopy->id]);
    DB::update('UPDATE boards SET pending_operation = ? WHERE id = ?', ['building', $fresh->id]);

    Queue::fake([DeleteBoardJob::class]);
    (new SweepWhiteboardJob)->handle();

    Queue::assertPushed(DeleteBoardJob::class, fn (DeleteBoardJob $job) => $job->boardId === $this->board->id);
    Queue::assertPushed(DeleteBoardJob::class, fn (DeleteBoardJob $job) => $job->boardId === $stuckCopy->id);
    Queue::assertNotPushed(DeleteBoardJob::class, fn (DeleteBoardJob $job) => $job->boardId === $fresh->id);
});

it('is scheduled every five minutes', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->description, 'SweepWhiteboardJob'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *');
});

it('refuses to copy an asset that is not on our own disk', function (): void {
    $remote = MediaAsset::factory()->create(['provider' => 'bunny', 'workspace_id' => $this->workspace->getKey()]);

    expect(fn () => app(CopyLocalMediaAsset::class)->handle($remote, $this->board, $this->workspace->getKey()))
        ->toThrow(LogicException::class);
});
