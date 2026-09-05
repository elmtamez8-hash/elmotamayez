<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CMS\Http\Requests\CreateArticleRequest;
use App\Modules\CMS\Http\Resources\ArticleResource;
use App\Modules\CMS\Models\Article;
use App\Modules\CMS\Policies\ArticlePolicy;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /*
        | ⛔ THE NON-STAFF BRANCH WAS `status = published` AND NOTHING ELSE, ON A
        | ROUTE ANY SIGNED-IN ACCOUNT CAN REACH — and `WorkspaceScope::apply()`
        | adds NO condition when the context is null, which it always is for a
        | student (nothing on their path writes `users.last_workspace_id`). So a
        | student read every workspace's published articles, including the two
        | families the public blog deliberately withholds: a workspace that opted
        | OUT of the marketplace, and an article SCHEDULED for a date that has not
        | arrived — `whereNotNull('published_at')` is not `<= now()`.
        |
        | `publiclyListed()` is the one predicate the public blog itself starts
        | from (workspace participation + this model's own three conditions), so
        | the two reads cannot drift into two answers about one article.
        |
        | ⚠️ IT REPLACES THE ABSENT SCOPE, IT DOES NOT STACK ON TOP OF A PRESENT
        | ONE — the shape `StudentScope::applyIfUnscoped()` already settled. A
        | reader who HAS a workspace context is a MEMBER, already narrowed to that
        | workspace by `WorkspaceScope`, and there is no leak to close for them;
        | narrowing them by public listing as well would hide a teacher's own
        | articles from their own members the moment the workspace left the
        | marketplace, which is an entitlement change wearing a security fix's
        | clothes.
        |
        | `published_at <= now()` on both non-staff arms all the same: a scheduled
        | article is not published to anybody yet, member or not.
        */
        $user = $this->currentUser($request);

        if ($user->can(Permissions::CMS_CREATE)) {
            $query = Article::query();
        } elseif (app(WorkspaceContext::class)->id() !== null) {
            $query = Article::query()
                ->where('status', 'published')
                ->where('published_at', '<=', now());
        } else {
            $query = Article::query()->publiclyListed();
        }

        $articles = $query->with(['category', 'tags'])->orderByDesc('created_at')->paginate(15);

        // ⚠️ `->response()->getData(true)`, never the collection itself: wrapping a
        // paginator in `response()->json()` never calls `toResponse()`, so `links`
        // and `meta` are dropped in silence and the list caps at one page.
        return response()->json(ArticleResource::collection($articles)->response()->getData(true));
    }

    public function show(Article $article): JsonResponse
    {
        $this->authorize('view', $article);

        return response()->json(ArticleResource::make($article->load(['category', 'tags'])));
    }

    public function store(CreateArticleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['author_id'] = $this->currentUser($request)->getKey();

        /*
        | ⚠️ NO SLUG IS BUILT HERE ANY MORE. It used to be
        | `Str::slug($title.'-'.Str::random(6))` — a transliteration that turned
        | «خطة المراجعة النهائية» into `kht-almragaa-alnhayy`, plus six random
        | characters bolted onto the one field whose whole job is to be readable.
        | spatie's `HasSlug` on the model does it now, in Arabic, and answers a
        | collision by asking the table. See `Article::getSlugOptions()`.
        |
        | ⚠️ AND A DIRECT `status: published` NOW STAMPS ITS OWN TIMESTAMP.
        | `publicListingConstraints()` requires `published_at` to exist and to be
        | past, so an article created published and never passed through
        | `publish()` was «published» to its author, invisible to the public blog,
        | absent from the sitemap, and there was nothing on any screen to say so.
        */
        if (($data['status'] ?? 'draft') === 'published') {
            $this->guardPublishCapability($request);
            $data['published_at'] ??= now();
        }

        $article = Article::create($data);

        if (isset($data['tag_ids'])) {
            $article->tags()->sync($data['tag_ids']);
        }

        return response()->json(ArticleResource::make($article->fresh()), 201);
    }

    public function update(CreateArticleRequest $request, Article $article): JsonResponse
    {
        $this->authorize('update', $article);

        $data = $request->validated();

        /*
        | ⛔ `cms.publish` GUARDED A DOOR NOBODY OPENED. It is read by exactly one
        | thing in the tree — {@see \App\Modules\CMS\Policies\ArticlePolicy::publish()},
        | reached only from `POST /cms/articles/{article}/publish`, which no client
        | calls — while `store()` and `update()` took `status` straight out of the
        | payload under `cms.create`/`cms.update` alone. The matrix gives an
        | assistant-teacher both of those and withholds `cms.publish` and
        | `cms.delete` on purpose, so an assistant published to the teacher's public
        | blog (firing the IndexNow ping under their name) and took a live post back
        | down, by sending one field.
        |
        | ⚠️ BOTH DIRECTIONS. Unpublishing is the same withheld capability reached
        | from the other side: it takes a live post off the blog, which is what
        | `cms.delete` and `cms.publish` are held back for.
        |
        | ⚠️ AND `published_at` IS NOT ASKED ABOUT HERE, DELIBERATELY —
        | `CreateArticleRequest` does not validate it, so `validated()` never
        | carries it and this door cannot move the date at all. It IS editable in
        | the panel, where moving it into the future de-lists a live article
        | without touching `status`, and the field is disabled there under the same
        | permission. A condition written here for a key that cannot arrive is a
        | guard nothing exercises — which is the family of defect this whole batch
        | is closing.
        */
        if (($data['status'] ?? $article->status) !== $article->status) {
            $this->authorize('publish', $article);
        }

        if (($data['status'] ?? $article->status) === 'published') {
            $data['published_at'] ??= $article->published_at ?? now();
        }

        $article->update($data);

        if (isset($data['tag_ids'])) {
            $article->tags()->sync($data['tag_ids']);
        }

        return response()->json(ArticleResource::make($article->fresh()));
    }

    public function publish(Request $request, Article $article): JsonResponse
    {
        $this->authorize('publish', $article);

        $article->update([
            'status' => 'published',
            'published_at' => $article->published_at ?? now(),
        ]);

        return response()->json(ArticleResource::make($article->fresh()));
    }

    /**
     * The permission half of {@see ArticlePolicy::publish()},
     * for a row that does not exist yet.
     *
     * ⚠️ THE POLICY IS ASKED WHEREVER THERE IS A RECORD TO ASK IT ABOUT —
     * `update()` calls `authorize('publish', $article)`. A create has none, and
     * the policy's other half (`belongsToCurrentWorkspace`) is answered here by
     * construction: `BelongsToWorkspace` fills `workspace_id` from the current
     * context on create, so a new article is in the caller's own workspace or in
     * none at all.
     */
    private function guardPublishCapability(Request $request): void
    {
        abort_unless(
            $this->currentUser($request)->can(Permissions::CMS_PUBLISH),
            403,
            'ليست لديك صلاحيةُ نشرِ المقالات.',
        );
    }

    public function destroy(Request $request, Article $article): JsonResponse
    {
        $this->authorize('delete', $article);

        $article->delete();

        return response()->json(null, 204);
    }
}
