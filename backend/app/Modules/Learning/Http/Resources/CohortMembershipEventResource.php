<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Learning\Models\CohortMembershipEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the history (FR-034).
 *
 * ⚠️ THE ACTOR'S NAME COMES FROM `first_name` AND `last_name`, NEVER `name`.
 * `users` has no such column — it is an accessor over those two — so a
 * constrained eager load that omits them renders a blank name with a `200` and
 * no error. That defect shipped six times across four modules before it was
 * written down.
 *
 * @mixin CohortMembershipEvent
 */
class CohortMembershipEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'event' => $this->event,
            'reason' => $this->reason,
            'created_at' => $this->created_at,
            'cohort' => $this->whenLoaded('cohort', fn () => [
                'uuid' => $this->resource->cohort?->uuid,
                'name' => $this->resource->cohort?->name,
            ]),
            'from_cohort' => $this->whenLoaded('fromCohort', fn () => [
                'uuid' => $this->resource->fromCohort?->uuid,
                'name' => $this->resource->fromCohort?->name,
            ]),
            'student' => $this->whenLoaded('student', fn () => [
                'uuid' => $this->resource->student?->uuid,
                'name' => $this->resource->student?->name,
            ]),
            // `null` when the system did it — an event with nobody's name on it
            // is a fact about a sweep, and inventing an actor for it would put a
            // person's name on a decision they did not make.
            'actor' => $this->whenLoaded('actor', fn () => $this->resource->actor === null ? null : [
                'uuid' => $this->resource->actor->uuid,
                'name' => $this->resource->actor->name,
            ]),
        ];
    }
}
