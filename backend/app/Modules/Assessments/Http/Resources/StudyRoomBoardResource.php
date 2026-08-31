<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\StudyRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The live board of one room, over HTTP.
 *
 * ⚠️ THE EXACT SHAPE THE SOCKET FRAME CARRIES, AND ON PURPOSE. `GET /board` is
 * the fallback for a client with no socket — SC-015's rule from 010, that a
 * screen works without live delivery and is merely a refresh behind — so the two
 * must be one payload. Two shapes for one board is a component that renders one
 * of them and breaks on the other, in exactly the situation nobody tests: an
 * outage.
 *
 * ⚠️ `uuid` IS THE PARTICIPANT ROW'S, NEVER THE USER'S, and there is no question
 * text, no option and no correctness anywhere in it. The board is where the
 * licence to broadcast data stops.
 *
 * @mixin StudyRoom
 */
class StudyRoomBoardResource extends JsonResource
{
    /**
     * @param  list<array{uuid: string, name: string, score: int, answered: int}>  $rows
     */
    public function __construct(StudyRoom $room, private readonly array $rows)
    {
        parent::__construct($room);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'room_uuid' => (string) $this->uuid,
            'ends_at' => $this->ends_at->toIso8601String(),
            'rows' => $this->rows,
        ];
    }
}
