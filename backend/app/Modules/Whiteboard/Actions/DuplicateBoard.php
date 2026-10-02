<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Modules\Whiteboard\Jobs\DuplicateBoardJob;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardPlacement;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * «نسخ»: a new board, hidden (`building`) until a queued job has copied every page
 * and picture into it (US3). The copy is the ACTOR's, in the same course and
 * lesson — placed through `BoardPlacement` exactly as a new board is, so nobody
 * copies a board into a course they could not have created one in.
 *
 * ⚠️ THE CLAIM IS A CONDITIONAL UPDATE: the original is marked `duplicating`
 * `WHERE pending_operation IS NULL`, so a double click answers 409 — and while it
 * is set the page gate refuses every structural change to the original, so the
 * job copies a board that cannot change under it.
 */
final class DuplicateBoard extends Action
{
    public function __construct(private readonly BoardPlacement $placement) {}

    public function handle(Board $original, User $actor): Board
    {
        $original->loadMissing(['course:id,uuid', 'lesson:id,uuid']);
        [$courseId, $lessonId] = $this->placement->resolve(
            $original->course === null ? null : (string) $original->course->uuid,
            $original->lesson === null ? null : (string) $original->lesson->uuid,
            (int) $original->workspace_id,
            $actor,
        );

        return DB::transaction(function () use ($original, $actor, $courseId, $lessonId): Board {
            $claimed = DB::update(
                // `updated_at` too: the sweep reads it to tell a stuck job from a running one.
                'UPDATE boards SET pending_operation = ?, updated_at = ? WHERE id = ? AND pending_operation IS NULL',
                [BoardPendingOperation::Duplicating->value, now()->format('Y-m-d H:i:s'), $original->id],
            );

            if ($claimed !== 1) {
                throw new WhiteboardRefusal('operation_pending');
            }

            $copy = Board::query()->create([
                'workspace_id' => $original->workspace_id,
                'owner_user_id' => $actor->getKey(),
                'title' => mb_substr($original->title.' (نسخة)', 0, 160),
                'course_id' => $courseId,
                'lesson_id' => $lessonId,
                'background' => $original->background,
            ]);
            $copy->forceFill(['pending_operation' => BoardPendingOperation::Building])->save();

            DB::afterCommit(fn () => DuplicateBoardJob::dispatch((int) $original->id, (int) $copy->id));

            return $copy;
        });
    }
}
