<?php

declare(strict_types=1);

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Actions\ConvertBoardImport;
use App\Modules\Whiteboard\Enums\BoardImportStatus;
use App\Modules\Whiteboard\Jobs\ConvertBoardImportJob;
use App\Modules\Whiteboard\Jobs\SweepWhiteboardJob;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardImport;
use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\FakePdfPages;
use App\Modules\Whiteboard\Support\PdfPages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 · story 4 — a PDF imported as pages (owner, 2026-10-02: PDF and
| pictures only). Poppler is faked (FakePdfPages); the queue is `sync`, so
| «complete» runs the conversion before it answers.
*/

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());
    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->board = Board::factory()->withPages(2)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(),
    ]);
    $this->tab = (string) Str::uuid();
    $this->pdf = new FakePdfPages(pages: 3);
    app()->instance(PdfPages::class, $this->pdf);

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    app()->forgetScopedInstances();
    Sanctum::actingAs($this->teacher);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/lock', ['tab' => $this->tab])->assertOk();
});

/** Ask for an import after `$after` (a page uuid), and put `$bytes` where the upload would. */
function wbImport(object $test, string $bytes = "%PDF-1.4\n%fake\n", ?string $after = null): string
{
    $started = $test->postJson('/api/v1/boards/'.$test->board->uuid.'/imports', [
        'tab' => $test->tab, 'filename' => 'lesson.pdf', 'size' => strlen($bytes), 'after' => $after,
    ])->assertCreated();

    $import = BoardImport::query()->withoutWorkspaceScope()->where('uuid', $started->json('import.uuid'))->firstOrFail();
    $asset = MediaAsset::query()->withoutWorkspaceScope()->findOrFail($import->source_asset_id);
    $path = 'media/'.$asset->uuid;
    Storage::disk((string) config('media.disk'))->put($path, $bytes);
    $asset->forceFill(['provider_asset_id' => $path])->save();

    return (string) $import->uuid;
}

function wbPages(Board $board): array
{
    return BoardPage::query()->withoutWorkspaceScope()->where('board_id', $board->id)->orderBy('position')->get()->all();
}

it('turns a 3-page PDF into 3 pages after the page asked for, each a locked picture, and drops the PDF', function (): void {
    [$first, $second] = wbPages($this->board);
    $uuid = wbImport($this, after: $first->uuid);

    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$uuid.'/complete')
        ->assertStatus(202)->assertJsonPath('status', 'done')->assertJsonPath('pages_count', 3);

    $pages = wbPages($this->board);
    expect($pages)->toHaveLength(5)
        ->and($pages[0]->id)->toBe($first->id)
        ->and($pages[4]->id)->toBe($second->id)
        ->and(array_map(fn ($p) => $p->position, $pages))->toBe([1, 2, 3, 4, 5])
        ->and($this->board->fresh()->pages_count)->toBe(5);

    foreach ([1, 2, 3] as $n) {
        $scene = json_decode($pages[$n]->scene, true);
        $picture = $scene['elements'][0];
        expect($picture['type'])->toBe('image')
            ->and($picture['locked'])->toBeTrue()
            ->and($picture['customData'])->toMatchArray(['kind' => 'doc-background', 'page' => $n])
            ->and($scene['fileIds'])->toBe([$picture['fileId']])
            ->and($pages[$n]->background_asset_id)->not->toBeNull()
            ->and(MediaAsset::query()->withoutWorkspaceScope()->where('uuid', $picture['fileId'])->value('mime_type'))->toBe('image/jpeg');
    }

    $import = BoardImport::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
    expect($import->source_asset_id)->toBeNull(); // the PDF itself is gone
});

it('gives a portrait page as many screens as its picture needs', function (): void {
    $this->pdf->height = 2716; // A4 at 1920 wide

    $uuid = wbImport($this);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$uuid.'/complete')->assertStatus(202);

    $scene = json_decode(wbPages($this->board)[2]->scene, true);
    $frame = collect($scene['elements'])->firstWhere('type', 'frame');
    expect($frame['height'])->toBe(3 * 1080);
});

it('fails whole — no page, no picture — when the PDF has more pages than allowed', function (): void {
    PlatformSettings::set('whiteboard.import_max_pages', 2, $this->owner->getKey());

    $uuid = wbImport($this);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$uuid.'/complete')
        ->assertJsonPath('status', 'failed')->assertJsonPath('failure_reason', 'too_many_pages');

    expect(wbPages($this->board))->toHaveLength(2)
        ->and(MediaAsset::query()->withoutWorkspaceScope()->where('mime_type', 'image/jpeg')->count())->toBe(0);
});

it('fails `corrupt` on a file poppler cannot read', function (): void {
    $this->pdf->corrupt = true;

    $uuid = wbImport($this);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$uuid.'/complete')
        ->assertJsonPath('status', 'failed')->assertJsonPath('failure_reason', 'corrupt');
    expect(wbPages($this->board))->toHaveLength(2);
});

it('refuses bytes that are not a PDF, whatever the name says', function (): void {
    $uuid = wbImport($this, "\x89PNG\r\n\x1a\nnot a pdf");

    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$uuid.'/complete')
        ->assertStatus(422)->assertJsonPath('failure_reason', 'unsupported');
    expect($this->pdf->renders)->toBe(0);
});

it('refuses a file over the size limit before the upload, and one whose real size is over it at complete', function (): void {
    PlatformSettings::set('whiteboard.import_max_bytes', 100, $this->owner->getKey());

    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports', [
        'tab' => $this->tab, 'filename' => 'big.pdf', 'size' => 101,
    ])->assertStatus(422)->assertJsonPath('code', 'too_large');

    // Announced small, arrived big.
    $started = $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports', [
        'tab' => $this->tab, 'filename' => 'lesson.pdf', 'size' => 10,
    ])->assertCreated();
    $import = BoardImport::query()->withoutWorkspaceScope()->where('uuid', $started->json('import.uuid'))->firstOrFail();
    $asset = MediaAsset::query()->withoutWorkspaceScope()->findOrFail($import->source_asset_id);
    Storage::disk((string) config('media.disk'))->put('media/'.$asset->uuid, "%PDF-1.4\n".str_repeat('x', 200));
    $asset->forceFill(['provider_asset_id' => 'media/'.$asset->uuid])->save();

    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$import->uuid.'/complete')
        ->assertStatus(422)->assertJsonPath('code', 'too_large');
    expect($import->fresh()->status)->toBe(BoardImportStatus::Failed);
});

it('runs one import per teacher at a time', function (): void {
    wbImport($this);

    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports', [
        'tab' => $this->tab, 'filename' => 'second.pdf', 'size' => 10,
    ])->assertStatus(429)->assertJsonPath('code', 'import_in_progress');

    expect(BoardImport::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('starts nothing for a tab that does not hold the lock', function (): void {
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports', [
        'tab' => (string) Str::uuid(), 'filename' => 'lesson.pdf', 'size' => 10,
    ])->assertStatus(409)->assertJsonPath('code', 'lock_lost');
});

it('fails `too_many_pages` when the board would pass its page ceiling', function (): void {
    PlatformSettings::set('whiteboard.max_pages_per_board', 4, $this->owner->getKey());

    $uuid = wbImport($this);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$uuid.'/complete')
        ->assertJsonPath('status', 'failed')->assertJsonPath('failure_reason', 'too_many_pages');
    expect(wbPages($this->board))->toHaveLength(2);
});

it('appends at the end when the page asked for was deleted meanwhile', function (): void {
    Queue::fake([ConvertBoardImportJob::class]);
    [$first] = wbPages($this->board);
    $uuid = wbImport($this, after: $first->uuid);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$uuid.'/complete')->assertStatus(202)->assertJsonPath('status', 'queued');

    DB::table('board_imports')->where('uuid', $uuid)->update(['insert_after_page_id' => null]);
    $import = BoardImport::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
    app(ConvertBoardImport::class)->handle((int) $import->id);

    $pages = wbPages($this->board);
    expect($pages)->toHaveLength(5)->and($pages[0]->id)->toBe($first->id)
        ->and(json_decode($pages[4]->scene, true)['elements'][0]['customData']['page'])->toBe(3);
});

it('converts once when its job is sent twice', function (): void {
    Queue::fake([ConvertBoardImportJob::class]);
    $uuid = wbImport($this);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$uuid.'/complete')->assertJsonPath('status', 'queued')->assertJsonPath('position', 1);
    $id = (int) BoardImport::query()->withoutWorkspaceScope()->where('uuid', $uuid)->value('id');

    app(ConvertBoardImport::class)->handle($id);
    app(ConvertBoardImport::class)->handle($id);

    expect($this->pdf->renders)->toBe(1)->and(wbPages($this->board))->toHaveLength(5);
});

it('fails `board_deleted` when the board is being deleted', function (): void {
    Queue::fake([ConvertBoardImportJob::class]);
    $uuid = wbImport($this);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/imports/'.$uuid.'/complete')->assertStatus(202);
    DB::table('boards')->where('id', $this->board->id)->update(['pending_operation' => 'deleting']);

    $import = BoardImport::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
    app(ConvertBoardImport::class)->handle((int) $import->id);

    expect($import->fresh()->failure_reason?->value)->toBe('board_deleted')
        ->and(BoardPage::query()->withoutWorkspaceScope()->where('board_id', $this->board->id)->count())->toBe(2);
});

it('answers 404 for another board\'s import', function (): void {
    $uuid = wbImport($this);
    $other = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(),
    ]);

    $this->getJson('/api/v1/boards/'.$other->uuid.'/imports/'.$uuid)->assertNotFound();
    $this->postJson('/api/v1/boards/'.$other->uuid.'/imports/'.$uuid.'/complete')->assertNotFound();
});

it('sweeps what a crash left: a stuck conversion fails, a stuck upload fails, a lost job is sent again', function (): void {
    Queue::fake([ConvertBoardImportJob::class]);
    $long = Carbon::now()->subHours(2);

    $converting = wbImport($this);
    DB::table('board_imports')->where('uuid', $converting)->update(['status' => 'converting', 'started_at' => $long]);
    $this->travel(1)->seconds();
    DB::table('board_imports')->where('uuid', $converting)->update(['user_id' => $this->owner->id]); // free the teacher's one slot
    $uploading = wbImport($this);
    DB::table('board_imports')->where('uuid', $uploading)->update(['created_at' => $long, 'user_id' => $this->owner->id]);
    $queued = wbImport($this);
    DB::table('board_imports')->where('uuid', $queued)->update(['status' => 'queued', 'dispatched_at' => $long, 'dispatch_attempts' => 1]);

    (new SweepWhiteboardJob)->handle();

    $state = fn (string $uuid) => BoardImport::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
    expect($state($converting)->failure_reason?->value)->toBe('timeout')
        ->and($state($uploading)->status)->toBe(BoardImportStatus::Failed)
        ->and($state($queued)->status)->toBe(BoardImportStatus::Queued)
        ->and($state($queued)->dispatch_attempts)->toBe(2);
    Queue::assertPushed(ConvertBoardImportJob::class, 1);
});

it('runs on its own long queue', function (): void {
    expect((new ConvertBoardImportJob(1))->queue)->toBe('whiteboard-import')
        ->and(config('horizon.defaults.supervisor-whiteboard-import.connection'))->toBe('redis-long');
});

it('clears what a killed conversion left — the PDF and its unused pictures — and nothing a page uses', function (): void {
    Queue::fake([ConvertBoardImportJob::class]);
    $uuid = wbImport($this);
    $import = BoardImport::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
    DB::table('board_imports')->where('id', $import->id)->update(['status' => 'converting', 'started_at' => Carbon::now()->subHours(2)]);

    $picture = fn (string $name) => MediaAsset::factory()->create([
        'workspace_id' => $this->board->workspace_id, 'owner_type' => Board::class, 'owner_id' => $this->board->id,
        'mime_type' => 'image/jpeg', 'original_filename' => $name,
    ]);
    $orphan = $picture(ConvertBoardImport::pictureName($import, 1));
    $mine = $picture('my-photo.jpg'); // the teacher's own upload, untouched
    $used = $picture(ConvertBoardImport::pictureName($import, 2));
    DB::table('board_pages')->where('board_id', $this->board->id)->limit(1)->update(['background_asset_id' => $used->id]);

    (new SweepWhiteboardJob)->handle();

    expect(MediaAsset::query()->withoutWorkspaceScope()->find($orphan->id))->toBeNull()
        ->and(MediaAsset::query()->withoutWorkspaceScope()->find($mine->id))->not->toBeNull()
        ->and(MediaAsset::query()->withoutWorkspaceScope()->find($used->id))->not->toBeNull()
        ->and(MediaAsset::query()->withoutWorkspaceScope()->find($import->source_asset_id))->toBeNull()
        ->and($import->fresh()->failure_reason?->value)->toBe('timeout');
});
