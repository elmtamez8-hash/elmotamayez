<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Course */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'status' => $this->status,
            'is_published' => $this->isPublished(),
            'visibility' => $this->visibility,
            'is_sequential' => $this->is_sequential,
            'is_free' => $this->isFree(),
            'language' => $this->language,
            'duration_seconds' => $this->duration_seconds,
            'created_at' => $this->created_at,
            'sections' => CourseSectionResource::collection($this->whenLoaded('sections')),
        ];
    }
}
