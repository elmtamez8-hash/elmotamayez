<?php

declare(strict_types=1);

namespace App\Modules\CMS\Policies;

use App\Models\User;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class ArticlePolicy extends BasePolicy
{
    public function view(User $user, Article $article): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($article))->denied()) {
            return $workspaceCheck;
        }

        if ($article->isPublished()) {
            return Response::allow();
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
