<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Models\Chapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Chapter
 */
class ChapterResource extends JsonResource
{
    /**
     * Three serial ids used to be here — `id`, `section_id`, `course_id` — which
     * the constitution forbids in a payload outright.
     *
     * And the omission had teeth beyond the rule: creating a chapter and then
     * creating a lesson inside it is the ordinary two-step, and the response to
     * the first carried no identifier the second could use. Every unit test
     * passed, because they read `$chapter->uuid` off the model rather than out
     * of the response.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'section_uuid' => $this->section?->uuid,
            'title' => $this->title,
            'order' => $this->order,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_at' => $this->created_at,
        ];
    }
}
