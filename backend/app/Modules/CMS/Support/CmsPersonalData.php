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
        // TODO(013-US4): erase or anonymise this module's rows for the subject.
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
