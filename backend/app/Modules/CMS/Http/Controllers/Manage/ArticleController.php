<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\CMS\Http\Requests\SaveArticleRequest;
use App\Modules\CMS\Http\Resources\ArticleResource;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $articles = Article::query()
            ->with(['category', 'tags'])
            ->orderByDesc('created_at')
            ->paginate(15);

        // ⚠️ `->response()->getData(true)`, never the collection itself: wrapping
        // a paginator in `response()->json()` never calls `toResponse()`, so
        // `links` and `meta` are dropped in silence and the list caps at one page.
        return response()->json(ArticleResource::collection($articles)->response()->getData(true));
    }

    public function show(Article $article): JsonResponse
    {
        // Implicit binding is safe here, and only because of the gate above it:
        // the reader is a workspace MEMBER, so the scope resolves and another
        // teacher's article 404s before the policy is ever consulted.
        $this->authorize('update', $article);

        return response()->json(['data' => ArticleResource::make($article->load(['category', 'tags']))]);
    }

    public function store(SaveArticleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['author_id'] = $this->currentUser($request)->getKey();

        $this->guardPublishFields($request, $data, null);

        /*
        | ⚠️ A DIRECT `status: published` STAMPS ITS OWN TIMESTAMP.
        | `publicListingConstraints()` requires `published_at` to exist and to be
        | past, so an article created published with no date is «published» to its
        | author, invisible to the public blog, absent from the sitemap, and with
        | nothing on any screen to say so.
        |
        | No slug is built here: spatie's `HasSlug` does it on the model, in
        | Arabic, and answers a collision by asking the table.
        */
        if (($data['status'] ?? 'draft') === 'published') {
            $data['published_at'] ??= now();
        }

        $article = Article::create($data);

        if (isset($data['tag_ids'])) {
            $article->tags()->sync($data['tag_ids']);
        }

        // ⚠️ `->fresh()`: `status` has a column default, a default never reaches
        // the in-memory model, and the 201 would otherwise carry `status: null`
        // about a row that is a perfectly good draft.
        return response()->json(['data' => ArticleResource::make($article->fresh())], 201);
    }

    public function update(SaveArticleRequest $request, Article $article): JsonResponse
    {
        $this->authorize('update', $article);

        $data = $request->validated();

        $this->guardPublishFields($request, $data, $article);

        if (($data['status'] ?? $article->status) === 'published') {
            $data['published_at'] ??= $article->published_at ?? now();
        }

        $article->update($data);

        if (isset($data['tag_ids'])) {
            $article->tags()->sync($data['tag_ids']);
        }

        return response()->json(['data' => ArticleResource::make($article->fresh())]);
    }

    public function destroy(Article $article): JsonResponse
    {
        $this->authorize('delete', $article);

        $article->delete();

        return response()->json(null, 204);
    }

    /**
     * The two fields that decide whether the public sees the row, behind the one
     * permission held back for exactly that.
     *
     * ⛔ **`cms.publish` GUARDED A DOOR NOBODY OPENED FOR FOUR MONTHS.** Its only
     * reader was a policy method behind a route no client called, while the
     * surfaces that actually publish took `status` straight out of the payload
     * under `cms.create`/`cms.update`. The matrix gives an assistant-teacher both
     * of those and withholds `cms.publish` and `cms.delete` on purpose — so
     * without this an assistant publishes to the teacher's public blog, firing
     * the IndexNow ping under their name, and takes a live post back down, by
     * sending one field.
     *
     * ⚠️ BOTH FIELDS, BECAUSE `published_at` IS THE SAME CAPABILITY WEARING A
     * DATE. A value in the future de-lists a live article without `status` moving
     * at all. The deleted API could not be guarded this way — it never validated
     * the field, so the guard would have been unexercisable — which is why it is
     * written here, at the door that really can write it.
     *
     * ⚠️ AND IT ASKS WHETHER THE VALUE MOVED, NOT WHETHER IT WAS SENT. This
     * screen submits the whole article, so an assistant fixing a typo in a
     * published post sends `status` and `published_at` back unchanged — a guard
     * on presence would refuse them the one thing `cms.update` is for. The date
     * is compared as an INSTANT and not as a string: the client echoes an ISO
     * timestamp and the column casts to a different spelling of the same moment.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardPublishFields(Request $request, array $data, ?Article $article): void
    {
        $current = $article === null ? 'draft' : $article->status;

        $movesStatus = array_key_exists('status', $data)
            && $data['status'] !== null
            && $data['status'] !== $current;

        $movesDate = array_key_exists('published_at', $data) && $data['published_at'] !== null;

        if ($movesDate && $article?->published_at !== null) {
            $movesDate = ! $article->published_at->equalTo(
                CarbonImmutable::parse((string) $data['published_at']),
            );
        }

        if (! $movesStatus && ! $movesDate) {
            return;
        }

        /*
         * The policy is not asked instead: on a create there is no row to ask it
         * about, and its other half — «is this article in your workspace» — is
         * answered by construction there, because `BelongsToWorkspace` fills
         * `workspace_id` from the current context. On an update the caller has
         * already passed `authorize('update', $article)`, which asks it.
         */
        abort_unless(
            $this->currentUser($request)->can(Permissions::CMS_PUBLISH),
            403,
            'ليست لديك صلاحيةُ نشرِ المقالات.',
        );
    }
}
