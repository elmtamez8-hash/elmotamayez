<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Resources;

use App\Modules\Whiteboard\Actions\RecordBoardExport;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardLessonExport;
use Illuminate\Http\Request;

/**
 * A board opened on the canvas: the list fields plus every page, in order.
 *
 * @mixin Board
 */
class BoardDetailResource extends BoardResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Board $board */
        $board = $this->resource;

        return [
            ...parent::toArray($request),
            'pages' => PageResource::collection($board->pages)->toArray($request),
            // Story 5: where this board is attached, and whether the reader may
            // REPLACE it — the screen says «اطلب من مدرّس الكورس» before uploading.
            'exports' => $board->lessonExports->map(fn (BoardLessonExport $export): array => [
                'uuid' => $export->uuid,
                'lesson' => $export->lesson === null ? null : ['uuid' => $export->lesson->uuid, 'title' => $export->lesson->title],
                'attachment' => $export->mediaAsset === null ? null : ['uuid' => $export->mediaAsset->uuid],
                'can_replace' => $request->user() !== null && RecordBoardExport::canReplace($request->user(), $export->mediaAsset),
            ])->values()->all(),
        ];
    }
}
