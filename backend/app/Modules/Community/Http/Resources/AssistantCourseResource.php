<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Courses\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course as the team screen shows it: on an assistant's card and in the picker
 * that confines them. One shape for both, so the chip and the checkbox cannot
 * disagree about a course.
 *
 * ⚠️ `teacher` READS `creator` WITH `relationLoaded()`, never the bare property.
 * A Resource runs once per row, so an unloaded relation here is an N+1 by
 * construction; every caller eager-loads `creator:id,first_name,last_name`, and a
 * caller that forgets gets `null` rather than a query per course.
 *
 * ⚠️ NO PRICE. The authoring `CourseResource` carries `price_minor`; this one is
 * read by the workspace owner only today, but the shape is the assistant-facing
 * vocabulary, and an assistant never sees money (FR-008).
 *
 * @mixin Course
 */
class AssistantCourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $creator = $this->relationLoaded('creator') ? $this->creator : null;

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'cover_url' => $this->cover_path === null ? null : asset('storage/'.$this->cover_path),
            'status' => $this->status,
            'teacher' => $creator === null ? null : ['name' => $creator->name],
        ];
    }
}
