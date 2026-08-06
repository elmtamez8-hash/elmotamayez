<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Models\FreezePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FreezePeriod
 *
 * FR-044 — readable with its reason and its author. A suspended session has to
 * be explainable to the student who booked it, and "معلّقة" with no why is the
 * message that generates the support ticket.
 */
class FreezePeriodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'reason' => $this->reason,
            // Null means the whole workspace — every student of this teacher.
            'student' => $this->whenLoaded('student', fn (): ?array => $this->student === null ? null : [
                'uuid' => $this->student->uuid,
                'name' => $this->student->name,
            ]),
            'creator' => $this->whenLoaded('creator', fn (): ?array => $this->creator === null ? null : [
                'uuid' => $this->creator->uuid,
                'name' => $this->creator->name,
            ]),
        ];
    }
}
