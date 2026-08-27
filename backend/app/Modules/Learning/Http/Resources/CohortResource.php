<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Learning\Models\Cohort;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One group, as the picker and the teacher's screen read it.
 *
 * ⚠️ `seats_left` IS `null` WITH NO DECLARED CAPACITY — NEVER ZERO. "No ceiling"
 * and "no places left" are opposite facts, and a zero here renders the most open
 * group in the course as the one nobody can join.
 *
 * ⚠️ AND `schedule_preview` IS STAMPED ON FROM OUTSIDE, in bulk. A Resource runs
 * once per row, so asking the schedule directory in here is an N+1 by
 * construction — the caller asks once for the whole list and passes the answer
 * in. An unstamped resource sends an empty list rather than reaching for one,
 * because a Resource that quietly issues a query is the defect this note exists
 * to prevent.
 *
 * @mixin Cohort
 */
class CohortResource extends JsonResource
{
    /** @param  list<string>  $schedulePreview */
    public function __construct(
        Cohort $resource,
        private readonly array $schedulePreview = [],
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'capacity' => $this->capacity,
            'members_count' => $this->members_count,
            'seats_left' => $this->resource->seatsLeft(),
            'is_full' => $this->resource->isFull(),
            // The one predicate the picker and the safety valve share. Two
            // spellings of "joinable" put one answer on the screen and another
            // at the door.
            'is_joinable' => $this->resource->isJoinable(),
            'schedule_preview' => $this->schedulePreview,
        ];
    }
}
