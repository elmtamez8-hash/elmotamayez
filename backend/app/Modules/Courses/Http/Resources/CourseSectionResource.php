<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Section */
class CourseSectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'order' => $this->order,
            'status' => $this->status->value,
            'chapters' => $this->whenLoaded('chapters', fn () => $this->chapters->map(fn (Chapter $chapter): array => [
                'uuid' => $chapter->uuid,
                'title' => $chapter->title,
                'order' => $chapter->order,
                'status' => $chapter->status->value,
                'lessons' => $this->lessonsOf($chapter),
            ])->values()->all()),
        ];
    }

    /**
     * Lessons belong to the chapter, not the section — asking `whenLoaded()` on the
     * section would leak a MissingValue into the payload (serialized as `{}`, which
     * the frontend cannot map over).
     *
     * @return array<int, array<string, mixed>>
     */
    private function lessonsOf(Chapter $chapter): array
    {
        if (! $chapter->relationLoaded('lessons')) {
            return [];
        }

        return $chapter->lessons->map(fn (Lesson $lesson): array => [
            'uuid' => $lesson->uuid,
            'title' => $lesson->title,
            'type' => $lesson->type,
            'order' => $lesson->order,
            'status' => $lesson->status->value,
            'is_preview' => $lesson->is_preview,
            'duration_seconds' => $lesson->duration_seconds,
        ])->values()->all();
    }
}
