<?php

declare(strict_types=1);

namespace App\Modules\CMS\Actions;

use App\Modules\CMS\Models\Article;
use App\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The public blog index, and the sitemap's only source (011 · US5 · FR-032).
 *
 * ⚠️ THE QUERY MUST START FROM `publiclyListed()`. `WorkspaceScope` contributes
 * nothing on a guest request — it returns early when the context is null — so
 * dropping that scope does not widen the result set by one workspace, it
 * publishes every workspace's drafts at once.
 *
 * ⚠️ AND THE SITEMAP READS THIS SAME ACTION, deliberately. FR-036 says the map
 * carries the published and nothing else, which is exactly the predicate above; a
 * second query spelled for the sitemap is how `SC-011` («١٠٠٪ من المنشورِ وصفرٌ من
 * غيرِه») drifts into two different answers that both look right in isolation.
 * `perPage` is capped rather than free because the sitemap is the only caller
 * that wants a big page and a public endpoint is the wrong place to accept an
 * unbounded one.
 */
class ListPublicArticles extends Action
{
    public const MAX_PER_PAGE = 200;

    /** @return LengthAwarePaginator<int, Article> */
    public function handle(int $page = 1, int $perPage = 12, ?string $category = null, ?string $tag = null): LengthAwarePaginator
    {
        return Article::query()
            ->publiclyListed()
            ->with(['category:id,slug,name', 'tags:id,slug,name'])
            ->when($category !== null, fn ($query) => $query->whereHas(
                'category',
                fn ($sub) => $sub->where('cms_categories.slug', $category),
            ))
            ->when($tag !== null, fn ($query) => $query->whereHas(
                'tags',
                fn ($sub) => $sub->where('cms_tags.slug', $tag),
            ))
            // Newest first, and the index the previous phase added is
            // `(status, published_at)` for exactly this pair.
            ->orderByDesc('cms_articles.published_at')
            ->paginate(perPage: min($perPage, self::MAX_PER_PAGE), page: max($page, 1));
    }
}
