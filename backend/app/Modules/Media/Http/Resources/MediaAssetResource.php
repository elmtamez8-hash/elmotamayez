<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The owner's view of their own upload.
 *
 * Even here there is no provider identifier and no storage path: the teacher
 * needs to know whether the video is ready and why it failed, not where it sits.
 *
 * @mixin MediaAsset
 */
class MediaAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'role' => $this->role->value,
            // The view-only switch. Exposed because this is the OWNER's view of
            // their own upload — it is the control they are about to flip, not a
            // hint to a viewer.
            'is_downloadable' => $this->is_downloadable,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'duration_seconds' => $this->duration_seconds,
            'failure_reason' => $this->failure_reason,
            'ready_at' => $this->ready_at,
            'created_at' => $this->created_at,
        ];
    }
}
