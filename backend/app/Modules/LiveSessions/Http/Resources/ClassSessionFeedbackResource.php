<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Models\ClassSessionFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClassSessionFeedback */
class ClassSessionFeedbackResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'student_uuid' => $this->whenLoaded('student', fn (): ?string => $this->student?->uuid),
            'rating' => $this->rating,
            'note' => $this->note,
        ];
    }
}
