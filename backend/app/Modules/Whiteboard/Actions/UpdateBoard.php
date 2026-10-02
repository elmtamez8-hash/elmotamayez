<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Data\BoardData;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardOwnership;
use App\Modules\Whiteboard\Support\BoardPlacement;
use App\Shared\Actions\Action;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A board's name, background and placement (US1). Page content is not here — that
 * is `SaveBoardScene`, under the edit lock.
 *
 * ⚠️ MOVING A BOARD TO ANOTHER COURSE (or to none) CHANGES ITS OWNER. The owning
 * teacher is derived from the course (D1), so an assistant who could clear the
 * course would make themselves the owner — with «خُذ التحرير» and delete. Only the
 * current owning teacher moves a board, and the actor must still pass `update` on
 * the board as it would be AFTER the move, checked before the commit.
 */
final class UpdateBoard extends Action
{
    public function __construct(private readonly BoardPlacement $placement) {}

    public function handle(Board $board, BoardData $data, User $actor): Board
    {
        return DB::transaction(function () use ($board, $data, $actor): Board {
            if ($data->title !== null && $data->title !== '') {
                $board->title = $data->title;
            }

            if ($data->background !== null) {
                $board->background = $data->background;
            }

            if ($data->courseGiven || $data->lessonGiven) {
                if (BoardOwnership::owningTeacherId($board) !== (int) $actor->getKey()) {
                    throw new AuthorizationException('نقل السبّورة إلى كورس آخر لمدرّس الكورس وحده.');
                }

                [$courseId, $lessonId] = $this->placement->resolve(
                    $data->courseGiven ? $data->courseUuid : $board->course?->uuid,
                    $data->lessonGiven ? $data->lessonUuid : null,
                    $board->workspace_id,
                    $actor,
                );
                $board->course_id = $courseId;
                $board->lesson_id = $lessonId;
                $board->unsetRelation('course');

                if (Gate::forUser($actor)->denies('update', $board)) {
                    throw new AuthorizationException('بعد هذا النقل لن تستطيع تحرير السبّورة.');
                }
            }

            $board->save();

            return $board;
        });
    }
}
