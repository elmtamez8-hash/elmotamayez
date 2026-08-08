<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\MarkdownRenderer;
use App\Modules\Courses\Support\ReferenceSummary;
use App\Modules\Media\Models\MediaAsset;
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
            // What this item points at, and what it asks. Null on the eight types
            // that point at nothing, and null on a reference whose target has
            // been deleted — which is the same row the tree marks as broken.
            'reference' => ReferenceSummary::for($this->resource),
            'exam_gate' => $this->exam_gate?->value,
            'exam_gate_label' => $this->exam_gate?->label(),
            'is_completable' => LessonTypeRegistry::isCompletable($type),
            'is_recording' => $this->class_session_id !== null,
            'order' => $this->order,
            'duration_seconds' => $this->duration_seconds,
            'is_preview' => $this->is_preview,
            'is_free' => $this->is_free,
            'asset' => $this->asset($this->mediaAsset),
            // Files beside the item, whatever its type (FR-019). Separate from
            // `asset` rather than one list with a role flag: they answer
            // different questions — "what is this item" and "what comes with it"
            // — and a single list makes the first one a filter every caller has
            // to remember.
            'attachments' => $this->attachments->map(
                fn (MediaAsset $attachment): array => $this->asset($attachment) ?? [],
            )->values()->all(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * One asset, narrowly.
     *
     * `provider` and `provider_asset_id` must never reach a payload (004
     * FR-011), and the playable URL is not a property of the asset — it is
     * minted per viewer, per session, with an expiry.
     *
     * @return array<string, mixed>|null
     */
    private function asset(?MediaAsset $asset): ?array
    {
        if ($asset === null) {
            return null;
        }

        return [
            'uuid' => $asset->uuid,
            'kind' => $asset->kind->value,
            'kind_label' => $asset->kind->label(),
            'role' => $asset->role->value,
            'is_downloadable' => $asset->is_downloadable,
            'status' => $asset->status->value,
            'status_label' => $asset->status->label(),
            'original_filename' => $asset->original_filename,
            'duration_seconds' => $asset->duration_seconds,
            'failure_reason' => $asset->failure_reason,
        ];
    }
}
