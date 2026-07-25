<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Learning\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Enrollment */
class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'course_uuid' => $this->course?->uuid,
            'course_title' => $this->course?->title,
            'status' => $this->status,
            'source' => $this->source,
            'progress_pct' => $this->progress_pct,
            'enrolled_at' => $this->enrolled_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
