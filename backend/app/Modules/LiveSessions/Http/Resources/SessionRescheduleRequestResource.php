<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A postponement, as the student watches it and the teacher decides it.
 *
 * ⚠️ `decision_reason` TRAVELS TO THE STUDENT. A refusal with no reason reads as
 * a fault and is submitted again for ever, which is a queue the teacher then
 * clears twice.
 *
 * ⚠️ THE STUDENT'S NAME IS BEHIND `whenLoaded`, AND THE EAGER LOAD MUST NAME
 * `first_name` AND `last_name`. `users` has no `name` column — it is an accessor
 * — so `->with('student:id,uuid,name')` renders a blank byline on every row with
 * no error and a 200. Six call sites across four modules shipped that way.
 *
 * @mixin SessionRescheduleRequest
 */
class SessionRescheduleRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'from_starts_at' => $this->from_starts_at,
            'to_starts_at' => $this->to_starts_at,
            'student_reason' => $this->student_reason,
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
            'session' => $this->whenLoaded('classSession', fn () => [
                'uuid' => $this->resource->classSession?->uuid,
                'title' => $this->resource->classSession?->title,
            ]),
            'student' => $this->whenLoaded('student', fn () => [
                'uuid' => $this->resource->student?->uuid,
                'name' => $this->resource->student?->name,
            ]),
        ];
    }
}
