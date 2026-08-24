<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One notice, as its publisher reads it.
 *
 * ⚠️ THE TWO COUNTERS ARE STAMPED, NEVER COUNTED HERE. A Resource runs once per
 * row, so a count inside it is an N+1 by construction — the rule already written
 * down for `ClassSessionResource` and `WithholdingReader::stamp()`. The controller
 * asks `ReadAnnouncementStats::forMany()` once for the page and puts the answer
 * on the row; a missing key here means somebody forgot, and shows as a missing
 * key rather than as a page that quietly costs one query per announcement.
 *
 * ⚠️ AND THIS IS THE PUBLISHER'S PAYLOAD ALONE. There is no student announcements
 * screen — FR-044 makes the notification centre the delivery — so nothing here is
 * ever read by a recipient, and no field needs to be weighed against that.
 *
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'body' => $this->body,
            'scope' => $this->scope,
            'is_urgent' => $this->is_urgent,
            'is_published' => $this->published_at !== null,
            'is_hidden' => $this->hidden_at !== null,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'notified_count' => $this->stats['notified'] ?? null,
            'read_count' => $this->stats['read'] ?? null,
            'author_name' => $this->whenLoaded('author', fn () => $this->author?->name),
        ];
    }
}
