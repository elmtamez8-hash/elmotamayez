<?php

declare(strict_types=1);

namespace App\Modules\CMS\Policies;

use App\Models\User;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;

class ArticlePolicy extends BasePolicy
{
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
        | A RESOLVED context keeps the plain answer, mirroring
        | `ArticleController::index()` line for line: a workspace member is already
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

    public function publish(User $user, Article $article): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($article))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::CMS_PUBLISH)
            ? Response::allow()
            : Response::deny();
    }
}
