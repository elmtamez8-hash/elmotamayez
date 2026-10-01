<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use App\Modules\Courses\Support\MarkdownRenderer;
use App\Modules\Store\Models\StoreItem;
use Illuminate\Http\Request;

/**
 * The product page: the card plus the full Markdown description, which a list
 * of twenty cards has no reason to carry.
 *
 * @mixin StoreItem
 */
class PublicStoreItemDetailResource extends PublicStoreItemResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            // Markdown, rendered per response (raw HTML stripped at the parse) —
            // authored text is never stored as HTML (docs/gotchas/courses.md).
            'description_html' => MarkdownRenderer::toHtml($this->description),
        ];
    }
}
