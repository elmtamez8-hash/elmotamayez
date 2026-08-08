<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Section */
class SectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // uuid, not id: the constitution allows no serial key in a payload,
            // and sections had no public identifier at all until 016.
            'uuid' => $this->uuid,
            'course_uuid' => $this->course?->uuid,
            'title' => $this->title,
            'order' => $this->order,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_at' => $this->created_at,
        ];
    }
}
