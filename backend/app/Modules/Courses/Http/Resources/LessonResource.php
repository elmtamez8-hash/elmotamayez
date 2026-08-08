<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\MarkdownRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lesson */
class LessonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $type = LessonType::from($this->type);

        return [
            'uuid' => $this->uuid,
            // uuids, not the serial keys that used to be here. A payload that
            // exposes the autoincrement id is forbidden outright, and these
            // three were the identifiers a client would reach for next.
            'chapter_uuid' => $this->chapter?->uuid,
            'section_uuid' => $this->section?->uuid,
            'title' => $this->title,
            'type' => $type->value,
            'type_label' => $type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'content' => $this->content,
            // Derived per response, never stored: a saved second copy of the
            // same words drifts from the source at the first typo fix. Raw HTML
            // is stripped rather than escaped, so the allowlist is the Markdown
            // feature set itself.
            'content_html' => MarkdownRenderer::toHtml($this->content),
            'external_url' => $this->external_url,
            'is_completable' => LessonTypeRegistry::isCompletable($type),
            'is_recording' => $this->class_session_id !== null,
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
