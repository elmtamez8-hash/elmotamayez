<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Support\SessionSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request, as the student watches it and the teacher decides it.
 *
 * ⚠️ NO AMOUNT ANYWHERE (FR-028). A credit's price is the teacher's approved
 * settlement rate plus two platform constants, so a total shown to either side
 * is solvable for the other's rate across two package sizes. What travels is a
 * DURATION — which is what «حصة» means to both of them.
 *
 * ⚠️ AND `decision_reason` TRAVELS TO THE STUDENT. That is the whole of FR-018: a
 * refusal with no reason reads as a fault and is submitted again for ever, which
 * is a queue the teacher then clears twice.
 *
 * ⚠️ THE STUDENT'S NAME IS BEHIND `whenLoaded`, AND THE EAGER LOAD MUST NAME
 * `first_name` AND `last_name`. `users` has no `name` column — it is an accessor
 * — so `->with('student:id,uuid,name')` renders a blank byline on every row with
 * no error and a 200. Six call sites across four modules shipped that way.
 *
 * @mixin PrivateSessionRequest
 */
class PrivateSessionRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'starts_at' => $this->starts_at,
            'duration_minutes' => $this->duration_minutes,
            // The zone every time on this row is shown in — the same declared zone
            // `ClassSessionResource` sends, so a request and the lesson it becomes
            // read the same hour. Without it the screen falls back to the
            // browser's own zone, which is a different hour on a laptop still set
            // to last holiday's timezone.
            'timezone' => app(SessionSettings::class)->timezone(),
            'expires_at' => $this->expires_at,
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
            'course' => $this->whenLoaded('course', fn () => [
                'uuid' => $this->resource->course?->uuid,
                'title' => $this->resource->course?->title,
            ]),
            'student' => $this->whenLoaded('student', fn () => [
                'uuid' => $this->resource->student?->uuid,
                'name' => $this->resource->student?->name,
            ]),
            // The uuid alone: the granted lesson is read through the routes that
            // guard it, and an id here would be a second door onto a session.
            'class_session_uuid' => $this->whenLoaded(
                'classSession',
                fn () => $this->resource->classSession?->uuid,
            ),
        ];
    }
}
