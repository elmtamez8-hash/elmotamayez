<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\CMS\Actions\SaveArticle;
use App\Modules\CMS\Enums\ArticleStatus;
use App\Modules\CMS\Http\Requests\SaveArticleRequest;
use App\Modules\CMS\Http\Resources\ArticleResource;
use App\Modules\CMS\Models\Article;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The teacher's own blog: their articles, in their own workspace, and nobody
 * else's.
 *
 * ⛔ **THE CAPABILITY EXISTED AND THE DOOR DID NOT.** `cms.create`,
 * `cms.update`, `cms.delete` and `cms.publish` have sat in the teacher and
 * assistant roles since spec 011 — with a model, a policy, Arabic slug
 * generation, an IndexNow announcement, SEO fields, a sitemap and a public blog
 * all built around teacher-authored content — and after `/admin` was narrowed to
 * platform staff, **only the super admin could write an article**. Measured
 * 2026-09-05: `RolePermissionMatrix::platformPermissions()` contains no `cms.*`
 * at all, and the `finance-admin` and `compliance-officer` role rows hold four
 * and five permissions, none of them a CMS one. Four permissions that nobody on
 * the platform could exercise, guarding a feature nobody could reach.
 *
 * ⚠️ THIS IS NOT THE DELETED `/cms/articles` PUT BACK. That surface answered ANY
 * signed-in account, which is why it needed a three-armed index to keep a
 * student out of other workspaces' drafts — and shipped with that arm missing.
 * This one is gated on a permission a student cannot hold, which is what makes
 * the ordinary workspace scope the whole filter. See `index()`.
 */
class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /*
        | ⚠️ THE GATE IS `cms.update`, NEVER `cms.view` — AND THAT IS WHAT MAKES
        | THE SCOPE SAFE HERE. Every student holds `cms.view` by the matrix (it
        | means «may read the blog»), and a student is a member of no workspace,
        | so `WorkspaceContext::id()` is null and `WorkspaceScope::apply()` adds
        | NO condition — an index gated on `cms.view` would hand every student
        | every workspace's drafts. Not a hypothetical: the deleted
        | `/cms/articles` shipped exactly that leak.
        |
        | Holding `cms.update` is only possible with a RESOLVED workspace,
        | because spatie is in team mode and a null team id means no roles at
        | all. So the permission and the scope are one condition asked twice, and
        | this list cannot answer about a workspace the caller is not in.
        */
        $this->authorize('viewAny', Article::class);

        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            // An unknown status is a 422, never the unfiltered list.
            'status' => ['sometimes', 'nullable', Rule::enum(ArticleStatus::class)],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? null;

        /*
        | The search narrows BOTH the page and the chip counts; the status narrows
        | the page alone. A count that followed the chosen chip would make the
        | other chips read zero the moment one is pressed.
        */
        $base = Article::query()
            ->when($search !== '', fn (Builder $query): Builder => $query->where('title', 'like', '%'.$search.'%'));

        $articles = (clone $base)
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status))
            ->with(['category', 'tags'])
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        // ⚠️ `reorder()`, and the clone is taken before any ORDER BY: a GROUP BY
        // carrying `ORDER BY created_at` is green on SQLite and refused by
        // MySQL's ONLY_FULL_GROUP_BY.
        $counts = (clone $base)
            ->reorder()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // ⚠️ `->response()->getData(true)`, never the collection itself: wrapping
        // a paginator in `response()->json()` never calls `toResponse()`, so
        // `links` and `meta` are dropped in silence and the list caps at one page.
        $payload = ArticleResource::collection($articles)->response()->getData(true);
        $payload['meta']['counts'] = [
            ArticleStatus::Draft->value => (int) ($counts[ArticleStatus::Draft->value] ?? 0),
            ArticleStatus::Published->value => (int) ($counts[ArticleStatus::Published->value] ?? 0),
        ];

        return response()->json($payload);
    }

    public function show(Article $article): JsonResponse
    {
        // Implicit binding is safe here, and only because of the gate above it:
        // the reader is a workspace MEMBER, so the scope resolves and another
        // teacher's article 404s before the policy is ever consulted.
        $this->authorize('update', $article);

        return response()->json(['data' => ArticleResource::make($article->load(['category', 'tags']))]);
    }

    /*
    | ⚠️ THE RULES ARE IN `SaveArticle`, shared with both panel pages: the
    | `cms.publish` guard on the two fields that decide whether the public sees
    | the row, the `published_at` stamp, the author and the tag sync. No
    | workspace is passed here, so the article lands in the caller's own —
    | `cms.create` is only held with a resolved workspace (see `index()`).
    */
    public function store(SaveArticleRequest $request, SaveArticle $save): JsonResponse
    {
        $article = $save->handle($this->currentUser($request), $request->validated());

        return response()->json(['data' => ArticleResource::make($article)], 201);
    }

    public function update(SaveArticleRequest $request, Article $article, SaveArticle $save): JsonResponse
    {
        $this->authorize('update', $article);

        $article = $save->handle($this->currentUser($request), $request->validated(), $article);

        return response()->json(['data' => ArticleResource::make($article)]);
    }

    public function destroy(Article $article): JsonResponse
    {
        $this->authorize('delete', $article);

        $article->delete();

        return response()->json(null, 204);
    }
}
