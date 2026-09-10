<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Cache;

/**
 * The subjects and broad stages a SIGNUP FORM offers (spec 022 · FR-002).
 *
 * ⚠️ THIS IS A SECOND READ BESIDE {@see ListPublicTaxonomy}, NOT A FLAG ON IT.
 * That one drops every entry with no publicly listed teacher, and its own
 * docblock says why: a filter option that can only return an empty page is a
 * dead end dressed up as a starting point. True of the MARKETPLACE. On a
 * required signup field it is a CIRCULAR LOCK — no listed teacher ⇒ no subject
 * in the list ⇒ the first teacher on the platform can never apply.
 *
 * The alternative was an `includeEmpty` flag. Rejected: one function answering
 * two questions, where the first caller who forgets the flag restores the lock
 * silently. Two reads make `SC-008` — "the marketplace filter bar does not
 * widen" — true by construction rather than by test.
 *
 * The model is {@see ListRegions}, copied deliberately: full active vocabulary,
 * no participation condition, a plain array rather than a Resource.
 */
class ListSignupTaxonomy extends Action
{
    public const SUBJECTS = 'subjects';

    public const GRADE_LEVELS = 'grade_levels';

    /** @return list<array{slug: string, name: string}> */
    public function handle(string $taxonomy = self::SUBJECTS): array
    {
        /** @var class-string<Subject|GradeLevel> $model */
        $model = $taxonomy === self::GRADE_LEVELS ? GradeLevel::class : Subject::class;

        /** @var list<array{slug: string, name: string}> */
        return Cache::remember(
            // ⚠️ THE `signup:` PREFIX IS NOT DECORATION. `MarketplaceCache::key()`
            // is a FLAT namespace with a version counter folded in, and
            // `ListPublicTaxonomy` owns the bare suffixes `subjects` and
            // `grade_levels` literally. Without the prefix the two reads serve
            // each other's answers by whichever warmed the key first — the
            // circular lock back on a signup form, alternating, and nothing fails
            // anywhere.
            MarketplaceCache::key('signup:'.$taxonomy),
            MarketplaceCache::ttl(),
            fn (): array => $model::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['slug', 'name'])
                ->map(fn ($row): array => [
                    'slug' => (string) $row->slug,
                    'name' => (string) $row->name,
                ])
                ->all(),
        );
    }
}
