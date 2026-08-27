<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Community\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One notice, as the person it was addressed to reads it.
 *
 * ⚠️ NOT `Manage\AnnouncementResource` WITH FIELDS REMOVED. That one carries
 * `notified_count` and `read_count` — «how many did I reach», the publisher's
 * question and a headcount of the class handed to a member of it — plus the
 * draft and hidden flags, which describe a workflow the reader is not in. A
 * payload built by redaction grows the next field somebody adds to the teacher's
 * screen; a payload built from scratch does not.
 *
 * @mixin Announcement
 */
class CourseAnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'body' => $this->body,
            'is_urgent' => (bool) $this->is_urgent,
            'published_at' => $this->published_at?->toIso8601String(),
            'author_name' => $this->whenLoaded('author', fn (): ?string => $this->author?->name),
        ];
    }
}
