<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lesson */
class LessonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'course_id' => $this->course_id,
            'section_id' => $this->section_id,
            'chapter_id' => $this->chapter_id,
            'title' => $this->title,
            'type' => $this->type,
            'content' => $this->content,
            'order' => $this->order,
            'duration_seconds' => $this->duration_seconds,
            'is_preview' => $this->is_preview,
            'is_free' => $this->is_free,
            'media' => $this->media,
            'created_at' => $this->created_at,
        ];
    }
}
