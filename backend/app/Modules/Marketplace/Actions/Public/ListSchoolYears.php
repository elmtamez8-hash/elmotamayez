<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The individual years a student picks at registration (spec 022 · FR-002).
 *
 * A separate Action from {@see ListSignupTaxonomy} rather than a third branch
 * inside it: this query is not the same shape — it joins the stage and carries a
 * third key — and "one action serves both because the query is identical" is the
 * criterion `ListPublicTaxonomy` states in its own docblock.
 *
 * `activelyOffered()` is the ONE predicate: the same scope every `Rule::in` on
 * every signup form reads, so the door cannot accept what the screen does not
 * show.
 */
class ListSchoolYears extends Action
{
    /** @return list<array{slug: string, name: string, grade_level_slug: string}> */
    public function handle(): array
    {
        /** @var list<array{slug: string, name: string, grade_level_slug: string}> */
        return Cache::remember(
            // `signup:` prefix — see ListSignupTaxonomy for why the flat key
            // namespace makes it mandatory.
            MarketplaceCache::key('signup:school-years'),
            MarketplaceCache::ttl(),
            fn (): array => SchoolYear::query()
                ->activelyOffered()
                ->join('grade_levels', 'grade_levels.id', '=', 'school_years.grade_level_id')
                ->orderBy('school_years.sort_order')
                ->orderBy('school_years.id')
                ->get([
                    'school_years.slug as slug',
                    'school_years.name as name',
                    DB::raw('grade_levels.slug as grade_level_slug'),
                ])
                ->map(fn (SchoolYear $year): array => [
                    'slug' => (string) $year->getAttribute('slug'),
                    'name' => (string) $year->getAttribute('name'),
                    'grade_level_slug' => (string) $year->getAttribute('grade_level_slug'),
                ])
                ->all(),
        );
    }
}
