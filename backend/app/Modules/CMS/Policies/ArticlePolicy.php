<?php

declare(strict_types=1);

namespace App\Modules\CMS\Policies;

use App\Models\User;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;

/**
 * ⚠️ THERE IS NO `publish()` HERE ANY MORE, AND THAT IS NOT A GAP.
 *
 * It existed for `POST /cms/articles/{article}/publish`, deleted on 2026-09-05
 * along with the other five authoring routes: a second door onto
 * `CmsArticleResource` that nothing under `frontend/src` ever called. The
 * capability itself is untouched — `cms.publish` is read by
 * `CmsArticleResource::canPublish()`, which disables the two fields that decide
 * whether the public sees the row (`status` and `published_at`), on the screen
 * people actually use.
 *
 * The methods below are what Filament falls back to for anything the Resource
 * does not answer itself, and what `/manage/articles` — the teacher's own door,
 * built the same day — authorises every row against.
 */
class ArticlePolicy extends BasePolicy
{
    /**
     * May this account open the authoring list at all?
     *
     * ⚠️ `cms.update` AND NOT `cms.view`. Every student holds `cms.view` by the
     * matrix — it means «may read the blog» — and a student is a member of no
     * workspace, so `WorkspaceScope` adds no condition for them: a list gated on
     * `cms.view` returns every workspace's drafts to every student on the
     * platform. The deleted `/cms/articles` shipped that leak for four months.
     *
     * ⚠️ AND IT IS DECLARED AT ALL because a policy with no method for an
     * ability DENIES, silently and with no error anywhere. Filament never
     * consults it — `CmsArticleResource` overrides `canViewAny()` — so this
     * answers for the API alone.
     */
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::CMS_UPDATE)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, Article $article): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($article))->denied()) {
            return $workspaceCheck;
        }

        /*
        | ⚠️ «PUBLISHED» IS NOT A CONDITION ABOUT THE READER, AND FOR A STUDENT
        | NOTHING ABOVE IT IS EITHER. `belongsToCurrentWorkspace()` raises no
        | objection on a null context — deliberately, since denying locked every
        | real student out — and the context is always null for a student. So this
        | branch answered any signed-in account about any workspace's article,
        | including the two the public blog withholds: an unlisted workspace's,
        | and one scheduled for a date that has not arrived.
        |
        | A RESOLVED context keeps the plain answer: a workspace member is already
        | narrowed by the check above, and asking them for public listing would
        | hide a teacher's own published article from them the moment their
        | workspace left the marketplace.
        */
        if ($article->isPublished()) {
            $publiclyReadable = app(WorkspaceContext::class)->id() !== null
                || Article::query()->publiclyListed()->whereKey($article->getKey())->exists();

            if ($publiclyReadable) {
                return Response::allow();
            }
        }

        return $user->can(Permissions::CMS_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::CMS_CREATE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Article $article): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($article))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::CMS_UPDATE)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, Article $article): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($article))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::CMS_DELETE)
            ? Response::allow()
            : Response::deny();
    }
}
