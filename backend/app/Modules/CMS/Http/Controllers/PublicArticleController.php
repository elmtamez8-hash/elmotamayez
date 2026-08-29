<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CMS\Actions\ListPublicArticles;
use App\Modules\CMS\Actions\ReadPublicArticle;
use App\Modules\CMS\Http\Resources\PublicArticleResource;
use App\Modules\Marketplace\Actions\Public\RelatedTeachers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The blog, for anyone (011 · US5 · FR-032 · FR-039).
 *
 * No `auth:sanctum` and no policy: FR-032 says a published article is readable
 * without an account. The guard is `publiclyListed()` inside each Action, and it
 * is not optional — see `IsPubliclyListed` for why a public query without it
 * returns every workspace's drafts rather than merely too many rows.
 */
class PublicArticleController extends Controller
{
    public function index(Request $request, ListPublicArticles $articles): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            // Capped rather than free: the sitemap is the only caller that wants
            // a big page, and an unbounded one on a public route is a way to make
            // the server do arbitrary work per request.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.ListPublicArticles::MAX_PER_PAGE],
            'category' => ['nullable', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:255'],
        ]);

        $page = $articles->handle(
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 12),
            category: $validated['category'] ?? null,
            tag: $validated['tag'] ?? null,
        );

        return response()->json(PublicArticleResource::collection($page)->response()->getData(true));
    }

    /**
     * ⚠️ `{slug}` IS A PLAIN STRING, NOT A BOUND MODEL. Implicit binding resolves
     * by uuid with no `publiclyListed()` anywhere near it, and `WorkspaceScope`
     * contributes nothing to a guest's query — so the convenient spelling of this
     * route publishes every workspace's drafts by direct link.
     */
    public function show(string $slug, ReadPublicArticle $reader, RelatedTeachers $related): JsonResponse
    {
        $article = $reader->handle($slug);

        // FR-038 — and the refusal it carries («يُمنعُ أن تشمل معلَّقاً أو خارجاً
        // عن السوقِ العامّ») is `publiclyListed()` inside Marketplace's Action,
        // not a condition written a second time here.
        $links = $related->handle((int) $article->workspace_id);

        return response()->json([
            'data' => PublicArticleResource::detail($article, $links['teachers'], $links['courses'])
                ->resolve(),
        ]);
    }
}
