<?php

declare(strict_types=1);

namespace App\Modules\CMS\Actions;

use App\Modules\CMS\Models\Article;
use App\Shared\Actions\Action;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One article, for an anonymous reader (011 · US5 · FR-032 · FR-033).
 *
 * ⚠️ NEVER BIND THIS MODEL TO A PUBLIC ROUTE IMPLICITLY.
 * `Route::get('/public/articles/{article}')` resolves by uuid through
 * `getRouteKeyName()` and never touches `publiclyListed()` — and `WorkspaceScope`
 * adds no condition for a guest, so that one line publishes every workspace's
 * drafts by direct link. The route takes a plain string and this Action does the
 * resolving, the same shape `ShowPublicTeacher` already has.
 */
class ReadPublicArticle extends Action
{
    public function handle(string $slug): Article
    {
        $article = Article::query()
            ->publiclyListed()
            ->with(['category:id,slug,name', 'tags:id,slug,name'])
            ->where('cms_articles.slug', $slug)
            ->first();

        if ($article === null) {
            /*
            | One response for «no such article», «still a draft», «scheduled for
            | next week», «deleted» and «the workspace never opted into public
            | publishing». Distinguishing them would confirm that an unpublished
            | article exists at a guessed address, which is the whole of FR-033
            | read from the attacker's side.
            */
            throw new NotFoundHttpException('غير متاح');
        }

        return $article;
    }
}
