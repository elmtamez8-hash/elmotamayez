<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Shared\Support\UserClock;
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
    private function counterpartZone(Request $request): ?string
    {
        $readerIsStudent = (int) $request->user()?->getKey() === (int) $this->resource->student_user_id;

        if ($readerIsStudent) {
            $session = $this->resource->relationLoaded('classSession') ? $this->resource->classSession : null;
            $profile = $session !== null && $session->relationLoaded('teacherProfile') ? $session->teacherProfile : null;
            $teacher = $profile !== null && $profile->relationLoaded('user') ? $profile->user : null;

            return $teacher === null ? null : UserClock::zoneFor($teacher);
        }

        $student = $this->resource->relationLoaded('student') ? $this->resource->student : null;

        return $student === null ? null : UserClock::zoneFor($student);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'from_starts_at' => $this->from_starts_at,
            'to_starts_at' => $this->to_starts_at,
            // The zone every time on this row is shown in — the same declared zone
            // `ClassSessionResource` sends, so a request and the lesson it becomes
            // read the same hour. Without it the screen falls back to the
            // browser's own zone, which is a different hour on a laptop still set
            // to last holiday's timezone.
            'timezone' => app(SessionSettings::class)->timezone(),
            // The OTHER party's clock (owner decision 2026-09-26) — see
            // `PrivateSessionRequestResource`.
            'counterpart_timezone' => $this->counterpartZone($request),
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
