<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Resources;

use App\Modules\Whiteboard\Models\BoardPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One page. ⚠️ `scene` IS THE STORED TEXT, never decoded here: a 300-page board of
 * up to 50 MB decoded into PHP arrays would multiply its memory several times over
 * a 512 MB worker. The browser parses it, page by page.
 *
 * @mixin BoardPage
 */
class PageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BoardPage $page */
        $page = $this->resource;

        return [
            'uuid' => $page->uuid,
            'position' => $page->position,
            'version' => $page->version,
            'scene' => $page->scene,
            'background_file' => $page->background_asset_id === null ? null : $page->backgroundAsset?->uuid,
        ];
    }
}
