<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attendance
 *
 * The register row, including the fact that it was edited.
 *
 * `auto_status` ships alongside `status` deliberately (FR-025): a screen that
 * shows only the final mark hides that a person changed it, and a record which
 * hides having been edited is trusted more than it has earned.
 */
class AttendanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'auto_status' => $this->auto_status?->value,
            'auto_status_label' => $this->auto_status?->label(),
            'first_joined_at' => $this->first_joined_at?->toIso8601String(),
            'stay_seconds' => $this->stay_seconds,
            'was_overridden' => $this->wasOverridden(),
            'override_reason' => $this->override_reason,
            'overridden_at' => $this->overridden_at?->toIso8601String(),
            // An independent fact that never moved the status (FR-021د).
            'recording_watched_at' => $this->recording_watched_at?->toIso8601String(),
            'student' => $this->whenLoaded('student', fn (): ?array => $this->student === null ? null : [
                'uuid' => $this->student->uuid,
                'name' => $this->student->name,
            ]),
        ];
    }
}
