<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Data\BoardData;
use App\Modules\Whiteboard\Enums\BoardBackground;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\BoardPlacement;
use App\Modules\Whiteboard\Support\BoardScene;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A new board with its first, blank page (US1).
 *
 * The course and lesson are resolved and checked by `BoardPlacement` here, not only
 * in the request: the Action is the entrance the panel and the seeders share.
 */
final class CreateBoard extends Action
{
    public function __construct(private readonly BoardPlacement $placement) {}

    public function handle(BoardData $data, int $workspaceId, User $creator): Board
    {
        $session = $this->placement->session($data->sessionUuid, $workspaceId, $creator);
        // A board made from a live class hangs on that class's course unless told otherwise.
        $courseUuid = $data->courseUuid ?? ($data->lessonUuid === null ? $session?->course?->uuid : null);
        $courseFromSession = $data->courseUuid === null && $courseUuid !== null;
        [$courseId, $lessonId] = $this->placement->resolve($courseUuid, $data->lessonUuid, $workspaceId, $creator);
        $background = $data->background ?? BoardBackground::White;

        return DB::transaction(function () use ($data, $workspaceId, $creator, $courseId, $lessonId, $background, $session, $courseFromSession): Board {
            $board = Board::query()->create([
                'workspace_id' => $workspaceId,
                'owner_user_id' => $creator->getKey(),
                'title' => (string) $data->title,
                'course_id' => $courseId,
                'lesson_id' => $lessonId,
                'class_session_id' => $session?->getKey(),
                'background' => $background,
            ]);

            // A new row has no concurrent writer, so the count is set directly here;
            // every later change goes through BoardPageGate.
            // A course taken from the live class must not hand the board to that
            // course's teacher when the creator could then not draw on it (a
            // manager hosting a teacher's class, Q5): it stays course-less, theirs.
            if ($courseFromSession && Gate::forUser($creator)->denies('update', $board)) {
                $board->course_id = null;
            }

            $board->forceFill(['pages_count' => 1])->save();

            $pageUuid = (string) Str::orderedUuid();
            $scene = BoardScene::blank($pageUuid, $background->value);
            // The uuid is chosen first because the scene's frame carries it, and
            // `uuid` is not fillable — so it is set, not mass-assigned.
            $page = new BoardPage([
                'workspace_id' => $workspaceId,
                'board_id' => $board->getKey(),
                'position' => 1,
                'scene' => $scene,
                'scene_bytes' => strlen($scene),
            ]);
            $page->uuid = $pageUuid;
            $page->save();

            return $board;
        });
    }
}
