<?php

declare(strict_types=1);

namespace App\Modules\CMS\Support;

use App\Modules\CMS\Models\Article;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use Carbon\CarbonImmutable;

/**
 * CMS's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class CmsPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'cms';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['cms_authorship'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        /*
        | ⚠️ NO `withTrashed()`, AND THAT IS A FACT ABOUT THE MODEL RATHER THAN A
        | CHOICE. `cms_articles` HAS a `deleted_at` column and `Article` does NOT use
        | `SoftDeletes` — so nothing ever writes it, no row is ever hidden, and a
        | `withTrashed()` here would be a call to a method that does not exist. The
        | column is vestigial; the day the trait is added, this walk has to be
        | revisited or a deleted article silently leaves the archive.
        |
        | ⚠️ AND THE PAGE IS 200, NOT 500. Every row carries the full body of an
        | article — kilobytes each, where most personal rows are bytes — and the page
        | size is what bounds the peak, not the number of rows.
        */
        yield from ExportWalk::keyed(
            'cms_authorship',
            Article::query()->withoutWorkspaceScope()->where('author_id', $subject->user->getKey()),
            fn (Article $article): array => [
                'uuid' => $article->uuid,
                'title' => $article->title,
                'slug' => $article->slug,
                'excerpt' => $article->excerpt,
                // Their own words. An export of authorship that omitted what was
                // authored would be a list of titles, not a copy of the content.
                'body' => $article->body,
                'status' => $article->status,
                'published_at' => ExportWalk::at($article->published_at),
            ],
            size: 200,
        );
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode !== ErasureMode::Anonymise) {
            return 0;
        }

        /*
        | ⚠️ THE BYLINE IS SEVERED AT THE SOURCE, NOT HERE, AND `cms_articles.author_id`
        | IS DELIBERATELY LEFT ALONE. It is NOT NULL, so "severing" it would mean a
        | schema change and an article that belongs to nobody — while the account it
        | points at is already anonymised, which is exactly the outcome
        | `ErasureMode::Anonymise` describes. A published article keeps its place in
        | the site and stops naming a person, in one write, at the one row that
        | every module's foreign keys already reach.
        |
        | Returning zero is therefore the correct answer and not an unimplemented
        | one — which is why it is written out. A later reader finding an empty
        | method would add a delete here, and a delete would take the article down.
        */
        return 0;
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
