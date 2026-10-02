<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Resources;

use App\Modules\Whiteboard\Models\Board;
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
        ];
    }
}
