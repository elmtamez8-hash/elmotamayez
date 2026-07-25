<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

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
            'id' => $this->id,
            'title' => $this->title,
            'order' => $this->order,
            'is_published' => $this->is_published,
            'chapters' => $this->whenLoaded('chapters', fn () => $this->chapters->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'order' => $c->order,
                'lessons' => $this->whenLoaded('lessons', fn () => $c->lessons->map(fn ($l) => [
                    'uuid' => $l->uuid,
                    'title' => $l->title,
                    'type' => $l->type,
                    'order' => $l->order,
                    'is_preview' => $l->is_preview,
                    'duration_seconds' => $l->duration_seconds,
                ])),
            ])),
        ];
    }
}
