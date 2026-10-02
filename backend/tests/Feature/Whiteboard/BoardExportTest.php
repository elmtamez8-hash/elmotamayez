<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Actions\RecordBoardExport;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardLessonExport;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 · US5 — «إرفاق بمواد الدرس»: the board's PDF, uploaded through the
| lesson's own attachment door, is recorded as the board's export; exporting
| again REPLACES it (Q2), and only someone who may delete an attachment may.
*/

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());
    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->lesson = Lesson::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->course = Course::query()->findOrFail($this->lesson->course_id);
    $this->course->forceFill(['created_by' => $this->teacher->getKey()])->save();
    $this->board = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(),
    ]);

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    app()->forgetScopedInstances();
    Sanctum::actingAs($this->teacher);
});

/** A PDF attachment of `$lesson`, as the lesson door leaves it once complete. */
function wbExportPdf(Lesson $lesson, int $uploader, array $state = []): MediaAsset
{
    $path = 'media/'.Str::uuid().'.pdf';
    Storage::disk((string) config('media.disk'))->put($path, '%PDF-1.4');

    return MediaAsset::factory()->create([
        'workspace_id' => $lesson->workspace_id, 'owner_type' => $lesson->getMorphClass(), 'owner_id' => $lesson->getKey(),
        'kind' => MediaKind::Document, 'role' => MediaRole::Attachment, 'mime_type' => 'application/pdf',
        'original_filename' => 'board.pdf', 'provider_asset_id' => $path, 'duration_seconds' => null,
        'uploaded_by_user_id' => $uploader, ...$state,
    ]);
}

function wbExportUrl(Board $board, ?string $export = null): string
{
    return '/api/v1/boards/'.$board->uuid.'/lesson-exports'.($export === null ? '' : '/'.$export);
}

it('records the first attachment, shows it on the board, and refuses a second first one', function (): void {
    $pdf = wbExportPdf($this->lesson, $this->teacher->id);

    $first = $this->postJson(wbExportUrl($this->board), ['lesson' => $this->lesson->uuid, 'asset' => $pdf->uuid])
        ->assertCreated()
        ->assertJsonPath('attachment.uuid', (string) $pdf->uuid)
        ->assertJsonPath('replaced', false);

    $this->getJson('/api/v1/boards/'.$this->board->uuid)
        ->assertJsonPath('exports.0.uuid', $first->json('export'))
        ->assertJsonPath('exports.0.lesson.uuid', (string) $this->lesson->uuid)
        ->assertJsonPath('exports.0.attachment.uuid', (string) $pdf->uuid)
        ->assertJsonPath('exports.0.can_replace', true);

    $again = wbExportPdf($this->lesson, $this->teacher->id);
    $this->postJson(wbExportUrl($this->board), ['lesson' => $this->lesson->uuid, 'asset' => $again->uuid])
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_exported');
});

it('replaces the attachment: one export, the new file, the old one deleted', function (): void {
    $old = wbExportPdf($this->lesson, $this->teacher->id);
    $export = BoardLessonExport::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'board_id' => $this->board->id, 'lesson_id' => $this->lesson->id, 'media_asset_id' => $old->id,
    ]);
    $new = wbExportPdf($this->lesson, $this->teacher->id);

    $this->putJson(wbExportUrl($this->board, $export->uuid), ['asset' => $new->uuid])
        ->assertOk()
        ->assertJsonPath('attachment.uuid', (string) $new->uuid)
        ->assertJsonPath('replaced', true);

    expect($export->fresh()->media_asset_id)->toBe($new->id)
        ->and(MediaAsset::query()->find($old->id))->toBeNull()
        ->and(BoardLessonExport::query()->where('board_id', $this->board->id)->count())->toBe(1);

    // The same file again changes nothing and deletes nothing.
    $this->putJson(wbExportUrl($this->board, $export->uuid), ['asset' => $new->uuid])->assertOk()->assertJsonPath('replaced', false);
    expect(MediaAsset::query()->find($new->id))->not->toBeNull();
});

it('refuses a replacement to an assistant who may not delete attachments, and keeps the old one', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->board->update(['course_id' => $this->course->id]);
    $old = wbExportPdf($this->lesson, $this->teacher->id);
    $export = BoardLessonExport::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'board_id' => $this->board->id, 'lesson_id' => $this->lesson->id, 'media_asset_id' => $old->id,
    ]);
    $this->setCurrentWorkspace($this->workspace, $assistant);
    app()->forgetScopedInstances();
    Sanctum::actingAs($assistant);
    expect($assistant->can('lessons.delete'))->toBeFalse();
    $new = wbExportPdf($this->lesson, $assistant->id);

    $this->getJson('/api/v1/boards/'.$this->board->uuid)->assertJsonPath('exports.0.can_replace', false);
    $this->putJson(wbExportUrl($this->board, $export->uuid), ['asset' => $new->uuid])
        ->assertForbidden()
        ->assertJsonPath('code', 'replace_forbidden');

    expect($export->fresh()->media_asset_id)->toBe($old->id)
        ->and(MediaAsset::query()->find($old->id))->not->toBeNull()
        // Their own fresh upload, linked to nothing, does not stay on the lesson.
        ->and(MediaAsset::query()->find($new->id))->toBeNull();
});

it('refuses a replacement to a teacher past their two-factor deadline', function (): void {
    $old = wbExportPdf($this->lesson, $this->teacher->id);
    $export = BoardLessonExport::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'board_id' => $this->board->id, 'lesson_id' => $this->lesson->id, 'media_asset_id' => $old->id,
    ]);
    $this->teacher->securitySettings()->updateOrCreate([], ['two_factor_required_at' => CarbonImmutable::now()->subDay()]);

    $this->putJson(wbExportUrl($this->board, $export->uuid), ['asset' => wbExportPdf($this->lesson, $this->teacher->id)->uuid])->assertForbidden();
    expect($export->fresh()->media_asset_id)->toBe($old->id);
});

it('refuses a file that is not this lesson\'s ready PDF attachment, linked to nothing', function (): void {
    $otherLesson = Lesson::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $linked = wbExportPdf($this->lesson, $this->teacher->id);
    $otherBoard = Board::factory()->withPages(1)->create(['workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey()]);
    BoardLessonExport::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'board_id' => $otherBoard->id, 'lesson_id' => $this->lesson->id, 'media_asset_id' => $linked->id,
    ]);

    $cases = [
        'asset_mismatch' => [
            wbExportPdf($otherLesson, $this->teacher->id),
            wbExportPdf($this->lesson, $this->teacher->id, ['role' => MediaRole::Primary]),
            wbExportPdf($this->lesson, $this->teacher->id, ['mime_type' => 'image/png']),
            $linked,
        ],
        'asset_not_ready' => [wbExportPdf($this->lesson, $this->teacher->id, ['status' => MediaAssetStatus::Pending, 'ready_at' => null])],
    ];

    foreach ($cases as $code => $assets) {
        foreach ($assets as $asset) {
            $this->postJson(wbExportUrl($this->board), ['lesson' => $this->lesson->uuid, 'asset' => $asset->uuid])
                ->assertStatus(422)
                ->assertJsonPath('code', $code);
            expect(MediaAsset::query()->find($asset->id))->not->toBeNull(); // a refusal deletes nothing passed by uuid
        }
    }
});

it('loses a replacement race without deleting what the winner linked', function (): void {
    $old = wbExportPdf($this->lesson, $this->teacher->id);
    $export = BoardLessonExport::factory()->create([
        'workspace_id' => $this->workspace->getKey(), 'board_id' => $this->board->id, 'lesson_id' => $this->lesson->id, 'media_asset_id' => $old->id,
    ]);
    $winner = wbExportPdf($this->lesson, $this->teacher->id);
    $mine = wbExportPdf($this->lesson, $this->teacher->id);
    $stale = $export->replicate()->forceFill(['id' => $export->id, 'uuid' => $export->uuid]);
    $stale->exists = true;
    // The other tab's swap lands between this one's read and its write.
    BoardLessonExport::query()->whereKey($export->id)->update(['media_asset_id' => $winner->id]);

    expect(fn () => app(RecordBoardExport::class)->handle($this->teacher, $this->board, $this->lesson, $mine, $stale))
        ->toThrow(WhiteboardRefusal::class);

    expect($export->fresh()->media_asset_id)->toBe($winner->id)
        ->and(MediaAsset::query()->find($winner->id))->not->toBeNull()
        ->and(MediaAsset::query()->find($mine->id))->toBeNull();
});

it('reads another workspace\'s lesson as missing', function (): void {
    [$otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);
    $foreign = app(WorkspaceContext::class)->forWorkspace($otherWorkspace, fn () => Lesson::factory()->create(['workspace_id' => $otherWorkspace->getKey()]));

    $this->postJson(wbExportUrl($this->board), ['lesson' => $foreign->uuid, 'asset' => wbExportPdf($this->lesson, $this->teacher->id)->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lesson');
});
