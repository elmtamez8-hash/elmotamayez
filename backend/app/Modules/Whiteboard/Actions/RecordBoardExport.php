<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardLessonExport;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Shared\Actions\Action;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * «إرفاق بمواد الدرس» (story 5): the board's PDF was uploaded through the
 * lesson's OWN attachment door (Media, its authorisation), and this records
 * which board it came from — so exporting again REPLACES it (Q2) instead of
 * stacking a second PDF on the lesson.
 *
 * Two doors (contracts/api.md): the first attachment, and a replacement. The
 * replacement deletes the old attachment, so it asks what deleting any
 * attachment asks (`MediaAssetPolicy::delete`) and sits behind `2fa.required`
 * on its own route (D2); the first attachment asks neither, as adding any
 * attachment does not.
 *
 * The swap is a CONDITIONAL update (`WHERE media_asset_id = old`), never a
 * read then a write, and the old file is deleted after the commit only when
 * the swap took. A refused or lost swap deletes the NEW file — but only the
 * caller's own fresh upload, never an attachment someone passed by uuid.
 */
class RecordBoardExport extends Action
{
    /** How fresh «the upload just made for this export» is. */
    private const FRESH_MINUTES = 30;

    public function __construct(private readonly DeleteMediaAsset $delete) {}

    /** @return array{export: BoardLessonExport, replaced: bool} */
    public function handle(User $user, Board $board, Lesson $lesson, MediaAsset $new, ?BoardLessonExport $replacing = null): array
    {
        $this->check($user, $lesson, $new, $replacing);

        if ($replacing === null) {
            $existing = BoardLessonExport::query()
                ->where('board_id', $board->id)
                ->where('lesson_id', $lesson->id)
                ->first();
            // An export whose file was deleted (`nullOnDelete`) is attached afresh.
            if ($existing?->media_asset_id !== null) {
                throw new WhiteboardRefusal('already_exported', ['export' => $existing->uuid]);
            }

            return ['export' => $this->attachFirst($user, $board, $lesson, $new, $existing), 'replaced' => false];
        }

        if ($replacing->lesson_id !== $lesson->id) {
            throw new WhiteboardRefusal('asset_mismatch');
        }
        $oldId = $replacing->media_asset_id;
        if ($oldId === null) {
            return ['export' => $this->attachFirst($user, $board, $lesson, $new, $replacing), 'replaced' => false];
        }
        if ($oldId === $new->id) {
            return ['export' => $replacing, 'replaced' => false];
        }

        $old = MediaAsset::query()->find($oldId);
        if ($old !== null && ! Gate::forUser($user)->allows('delete', $old)) {
            $this->discard($user, $new);
            throw new WhiteboardRefusal('replace_forbidden');
        }

        $swapped = BoardLessonExport::query()
            ->whereKey($replacing->id)
            ->where('media_asset_id', $oldId)
            ->update(['media_asset_id' => $new->id, 'updated_at' => now()]);

        if ($swapped !== 1) {
            // Another replacement got there first: this file is linked to nothing.
            $this->discard($user, $new);
            throw new WhiteboardRefusal('already_exported', ['export' => $replacing->uuid]);
        }

        if ($old !== null) {
            DB::afterCommit(fn () => $this->delete->handle($old));
        }

        return ['export' => $replacing->refresh(), 'replaced' => true];
    }

    private function check(User $user, Lesson $lesson, MediaAsset $new, ?BoardLessonExport $replacing): void
    {
        // The lesson door's own question, asked again here: the file was uploaded
        // through it, and recording the link is attaching to that lesson.
        Gate::forUser($user)->authorize('create', [MediaAsset::class, $lesson]);
        if ($new->status !== MediaAssetStatus::Ready) {
            throw new WhiteboardRefusal('asset_not_ready');
        }
        $ownedByLesson = $new->owner_type === $lesson->getMorphClass() && (int) $new->owner_id === (int) $lesson->id;
        // Linked to ANOTHER export; the one being replaced may already name it (sent twice).
        $replacingId = $replacing?->id;
        $linkedElsewhere = BoardLessonExport::query()
            ->where('media_asset_id', $new->id)
            ->when($replacingId !== null, fn ($query) => $query->whereKeyNot($replacingId))
            ->exists();
        if (! $ownedByLesson || $new->role !== MediaRole::Attachment || $new->mime_type !== 'application/pdf' || $linkedElsewhere) {
            throw new WhiteboardRefusal('asset_mismatch');
        }
    }

    private function attachFirst(User $user, Board $board, Lesson $lesson, MediaAsset $new, ?BoardLessonExport $existing): BoardLessonExport
    {
        if ($existing !== null) {
            $taken = BoardLessonExport::query()
                ->whereKey($existing->id)
                ->whereNull('media_asset_id')
                ->update(['media_asset_id' => $new->id, 'updated_at' => now()]);
            if ($taken !== 1) {
                $this->discard($user, $new);
                throw new WhiteboardRefusal('already_exported', ['export' => $existing->uuid]);
            }

            return $existing->refresh();
        }

        try {
            return BoardLessonExport::query()->create([
                'workspace_id' => $board->workspace_id,
                'board_id' => $board->id,
                'lesson_id' => $lesson->id,
                'media_asset_id' => $new->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two first attachments at once: `unique(board_id, lesson_id)` decided.
            $this->discard($user, $new);
            throw new WhiteboardRefusal('already_exported');
        }
    }

    /** The caller's own fresh upload that ended up linked to nothing — and nothing else. */
    private function discard(User $user, MediaAsset $new): void
    {
        if ((int) $new->uploaded_by_user_id === (int) $user->id
            && $new->created_at !== null && $new->created_at->gt(now()->subMinutes(self::FRESH_MINUTES))
            && ! BoardLessonExport::query()->where('media_asset_id', $new->id)->exists()) {
            $this->delete->handle($new);
        }
    }
}
