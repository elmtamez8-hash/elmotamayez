<?php

declare(strict_types=1);

namespace App\Modules\CMS\Actions;

use App\Models\User;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Write an article — the one door the teacher's API and both panel pages share.
 *
 * ⚠️ THE RULES LIVED IN THREE PLACES AND DISAGREED. `ArticleController` guarded
 * the publish fields, stamped `published_at` keeping an existing date, set the
 * author and synced the tags; `CreateCmsArticle` and `EditCmsArticle` each
 * re-spelled the date stamp (without keeping the existing one), set no author,
 * synced nothing — and the create page was Filament's `new Article($data)`,
 * which stamped the OFFICER's workspace from their `last_workspace_id` and
 * answered 500 (`workspace_id` is NOT NULL) for an officer with none.
 *
 * ⚠️ A CREATE NAMES ITS WORKSPACE OR TAKES THE CALLER'S. The teacher's API
 * passes none, so `BelongsToWorkspace` fills the teacher's own — safe there,
 * because `cms.create` is only held with a resolved workspace. The panel passes
 * the workspace the super admin CHOSE; its own context is never the answer.
 */
class SaveArticle extends Action
{
    /**
     * @param  array<string, mixed>  $data  validated article fields; `tag_ids` is synced when present
     *
     * @throws AuthorizationException when the publish fields move without `cms.publish`
     * @throws DomainException when a create has no workspace to belong to
     */
    public function handle(User $actor, array $data, ?Article $article = null, ?int $workspaceId = null): Article
    {
        $this->guardPublishFields($actor, $data, $article);

        /*
        | ⚠️ AN EMPTY SLUG IS «BUILD IT» ON A CREATE AND «KEEP IT» ON AN EDIT —
        | never a blank written to the column. `HasSlug` generates only when the
        | field is empty AND unchanged, so an empty string reads as «the author
        | typed a slug»; and `doNotGenerateSlugsOnUpdate()` means nothing rebuilds
        | a cleared one — a published URL turned into `/blog/` and, at the second
        | one, a collision on the platform-wide unique index.
        */
        if (($data['slug'] ?? null) === null || $data['slug'] === '') {
            unset($data['slug']);
        }

        /*
        | ⚠️ A DIRECT `published` STAMPS ITS OWN TIMESTAMP, AND AN EDIT KEEPS THE
        | ONE IT HAS. `publicListingConstraints()` requires `published_at` to exist
        | and be past, so an article saved published with no date is «published»
        | to its author and invisible to the public blog, with nothing on any
        | screen to say so.
        */
        $status = $data['status'] ?? ($article === null ? 'draft' : $article->status);

        if ($status === 'published') {
            $data['published_at'] ??= $article === null ? now() : ($article->published_at ?? now());
        }

        $tagIds = array_key_exists('tag_ids', $data) && is_array($data['tag_ids']) ? $data['tag_ids'] : null;
        unset($data['tag_ids']);

        return DB::transaction(function () use ($actor, $data, $article, $workspaceId, $tagIds): Article {
            if ($article === null) {
                // Refused BEFORE the insert: `workspace_id` is NOT NULL, and an
                // officer with no context used to meet it as a raw 500.
                $workspaceId ??= app(WorkspaceContext::class)->id();

                if ($workspaceId === null) {
                    throw new DomainException('اختَرْ مساحةَ العملِ التي يُنشَرُ المقالُ باسمِها.');
                }

                $data['workspace_id'] = $workspaceId;
                $data['author_id'] = $actor->getKey();

                $article = Article::query()->create($data);
            } else {
                unset($data['workspace_id'], $data['author_id']);

                $article->update($data);
            }

            if ($tagIds !== null) {
                $article->tags()->sync($tagIds);
            }

            // `->fresh()`: `status` has a column default that never reaches the
            // in-memory model, and callers answer with what was STORED.
            return $article->fresh() ?? $article;
        });
    }

    /**
     * The two fields that decide whether the public sees the row, behind the one
     * permission held back for exactly that.
     *
     * ⛔ `cms.publish` GUARDED A DOOR NOBODY OPENED FOR FOUR MONTHS: the surfaces
     * that actually publish took `status` straight from the payload under
     * `cms.create`/`cms.update`, which the matrix gives an assistant-teacher.
     *
     * ⚠️ BOTH FIELDS, because `published_at` is the same capability wearing a
     * date — a value in the future de-lists a live article without `status`
     * moving. And it asks whether the value MOVED, not whether it was sent: a
     * screen submits the whole article, so an assistant fixing a typo in a
     * published post echoes both back unchanged. The date is compared as an
     * INSTANT — the client echoes an ISO timestamp, the column casts to another
     * spelling of the same moment.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardPublishFields(User $actor, array $data, ?Article $article): void
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

        if (($movesStatus || $movesDate) && ! $actor->can(Permissions::CMS_PUBLISH)) {
            throw new AuthorizationException('ليست لديك صلاحيةُ نشرِ المقالات.');
        }
    }
}
