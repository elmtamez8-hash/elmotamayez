<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Resources;

use App\Modules\Whiteboard\Models\BoardLibraryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One shape of the academy's library. `elements` is the stored JSON, decoded;
 * `can_delete` is the policy's answer for the reader, so the screen never
 * guesses who may remove what.
 *
 * @mixin BoardLibraryItem
 */
class BoardLibraryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'elements' => json_decode($this->elements, true),
            'shared_by' => $this->creator?->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'can_delete' => (bool) $request->user()?->can('delete', $this->resource),
        ];
    }
}
