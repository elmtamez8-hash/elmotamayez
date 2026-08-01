<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subject or grade level as seen publicly.
 *
 * Exposes slug rather than uuid or id: the same subject is a distinct row per
 * workspace, so the slug is the only identifier that means the same thing across
 * the whole marketplace.
 */
class PublicTaxonomyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->resource->getAttribute('slug'),
            'name_ar' => $this->resource->getAttribute('name_ar'),
            'icon' => $this->resource->getAttribute('icon'),
        ];
    }
}
