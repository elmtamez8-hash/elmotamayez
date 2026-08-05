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
            // Replaces `media`. Deliberately narrow: `provider` and
            // `provider_asset_id` must never reach a payload (FR-011), and the
            // playable URL is not a property of the asset — it is minted per
            // viewer, per session, with an expiry.
            'asset' => $this->mediaAsset === null ? null : [
                'uuid' => $this->mediaAsset->uuid,
                'status' => $this->mediaAsset->status->value,
                'status_label' => $this->mediaAsset->status->label(),
                'duration_seconds' => $this->mediaAsset->duration_seconds,
                'failure_reason' => $this->mediaAsset->failure_reason,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
