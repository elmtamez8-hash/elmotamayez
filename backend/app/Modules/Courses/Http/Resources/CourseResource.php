<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Course */
class CourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            /*
            | The subject the course teaches — required on every write since the
            | day it was found NULL on 77 of 77 rows. Sent as a pair so the edit
            | form can preselect it without a second lookup, and `whenLoaded` so a
            | list that does not eager-load it pays nothing.
            */
            'subject' => $this->whenLoaded('subject', fn (): ?array => $this->subject === null ? null : [
                'uuid' => (string) $this->subject->uuid,
                'label' => (string) $this->subject->name_ar,
            ]),
            /*
            | ⚠️ THE SPELLING OF `PublicCourseCardResource:31`, CHARACTER FOR
            | CHARACTER, AND THE COVER WAS MISSING ONLY FROM THE AUTHENTICATED
            | SIDE. A visitor browsing the marketplace saw the course's cover on
            | its card; the student who bought it saw a page with no image on it
            | at all, because this resource never carried the field.
            |
            | Two spellings of one URL diverge at the first change to the storage
            | disk — and the half nobody opened is the half that breaks.
            */
            'cover_url' => $this->cover_path === null ? null : asset('storage/'.$this->cover_path),
            'slug' => $this->slug,
            'description' => $this->description,
            'price_minor' => $this->price_minor,
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
