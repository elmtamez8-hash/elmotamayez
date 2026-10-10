<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Identity\Support\TwoFactorMandate;
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

    /**
     * May `$user` replace the export holding `$old`? What deleting it asks, and
     * the two-factor deadline the replacement's route enforces — so the screen
     * says so BEFORE the board is drawn and uploaded, not after.
     */
    public static function canReplace(User $user, ?MediaAsset $old): bool
    {
        return $old === null
            || (Gate::forUser($user)->allows('delete', $old) && TwoFactorMandate::refusalFor($user) === null);
    }

    /** @return array{export: BoardLessonExport, replaced: bool} */
    public function handle(User $user, Board $board, Lesson $lesson, MediaAsset $new, ?BoardLessonExport $replacing = null): array
    {
        try {
            return $this->record($user, $board, $lesson, $new, $replacing);
        } catch (WhiteboardRefusal $refusal) {
            // Whatever refused, the caller's own fresh upload is linked to nothing
            // and must not stay on the lesson for students to see.
            $this->discard($user, $lesson, $new);
            throw $refusal;
        }
    }

    /** @return array{export: BoardLessonExport, replaced: bool} */
    private function record(User $user, Board $board, Lesson $lesson, MediaAsset $new, ?BoardLessonExport $replacing): array
    {
        $this->check($user, $lesson, $new, $replacing);

        if ($replacing === null) {
            $existing = BoardLessonExport::query()
                ->where('board_id', $board->id)
                ->where('lesson_id', $lesson->id)
                ->first();
            // An export whose file was deleted (`nullOnDelete`) is attached afresh.
            if ($existing?->media_asset_id !== null) {
                throw $this->taken($user, $existing);
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
            throw new WhiteboardRefusal('replace_forbidden');
        }

        $swapped = BoardLessonExport::query()
            ->whereKey($replacing->id)
            ->where('media_asset_id', $oldId)
            ->update(['media_asset_id' => $new->id, 'updated_at' => now()]);

        if ($swapped !== 1) {
            // Another replacement got there first.
            throw $this->taken($user, $replacing->refresh());
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
        // Only the upload just made for this export: a worksheet already on the
        // lesson, passed by uuid, would otherwise become «the board's PDF» and be
        // deleted by the next replacement.
        if (! $this->freshFrom($user, $new)) {
            throw new WhiteboardRefusal('asset_mismatch');
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
                throw $this->taken($user, $existing->refresh());
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
            $winner = BoardLessonExport::query()->where('board_id', $board->id)->where('lesson_id', $lesson->id)->first();
            throw $winner === null ? new WhiteboardRefusal('already_exported') : $this->taken($user, $winner);
        }
    }

    /** The caller's own fresh upload that ended up linked to nothing — and nothing else. */
    /**
     * ⛔ ONLY A FILE THAT COULD BE THIS EXPORT'S OWN UPLOAD (security scan
     * 2026-10-10, F29): a PDF attachment of the target lesson. «Fresh from the
     * caller» alone deleted ANY of their recent uploads named by uuid — a lesson's
     * published video, another course's attachment, a chat file — without
     * `lessons.delete`, the assistant scope or two-factor, the three things
     * `DELETE /media/assets/{asset}` asks.
     */
    private function discard(User $user, Lesson $lesson, MediaAsset $new): void
    {
        $couldBeOurs = $new->owner_type === $lesson->getMorphClass()
            && (int) $new->owner_id === (int) $lesson->id
            && $new->role === MediaRole::Attachment
            && $new->mime_type === 'application/pdf';

        if ($couldBeOurs && $this->freshFrom($user, $new) && ! BoardLessonExport::query()->where('media_asset_id', $new->id)->exists()) {
            $this->delete->handle($new);
        }
    }

    private function freshFrom(User $user, MediaAsset $asset): bool
    {
        return (int) $asset->uploaded_by_user_id === (int) $user->id
            && $asset->created_at !== null && $asset->created_at->gt(now()->subMinutes(self::FRESH_MINUTES));
    }

    /** «Already attached», with what the screen needs to switch to replacing it. */
    private function taken(User $user, BoardLessonExport $export): WhiteboardRefusal
    {
        $asset = $export->media_asset_id === null ? null : MediaAsset::query()->find($export->media_asset_id);

        return new WhiteboardRefusal('already_exported', [
            'export' => $export->uuid,
            'attachment' => $asset === null ? null : ['uuid' => $asset->uuid],
            'can_replace' => self::canReplace($user, $asset),
        ]);
    }
}
