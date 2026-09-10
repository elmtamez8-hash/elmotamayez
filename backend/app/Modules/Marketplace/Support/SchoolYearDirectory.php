<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\SchoolYear;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `slug ⇒ [stage slug, Arabic name]` for every school year, in ONE query.
 *
 * ⚠️ IT EXISTS BECAUSE A RESOURCE RUNS ONCE PER ROW. `FamilyController` renders
 * a guardian's children as a collection and the year lives on those rows as
 * TEXT with no relation behind it — so `->with()` is not even available, and a
 * per-row lookup is an N+1 by construction. The same shape that cost
 * `ClassSessionResource` a fix.
 *
 * ⚠️ BOUND `scoped()`, NOT `singleton()` AND NOT `bind()`. A queue worker's
 * container outlives the job, so a singleton would keep serving a renamed or
 * retired year until the worker restarts; `bind()` would rebuild the map several
 * times inside one request, which is the cost this class exists to remove.
 */
class SchoolYearDirectory
{
    /** @var array<string, array{stage: string, name: string}>|null */
    private ?array $map = null;

    /** @return array<string, array{stage: string, name: string}> */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        /** @var array<string, array{stage: string, name: string}> $map */
        $map = Cache::remember(
            // ⚠️ THE `signup:` PREFIX IS LOAD-BEARING. `MarketplaceCache::key()`
            // is a FLAT namespace and `ListPublicTaxonomy` already owns the bare
            // suffixes `subjects` and `grade_levels`; a collision would serve one
            // read's answer to the other, alternating, with nothing failing.
            MarketplaceCache::key('signup:school-year-map'),
            MarketplaceCache::ttl(),
            fn (): array => SchoolYear::query()
                ->join('grade_levels', 'grade_levels.id', '=', 'school_years.grade_level_id')
                ->orderBy('school_years.sort_order')
                ->orderBy('school_years.id')
                ->get([
                    'school_years.slug as slug',
                    'school_years.name as name',
                    DB::raw('grade_levels.slug as stage_slug'),
                ])
                ->mapWithKeys(fn (SchoolYear $year): array => [
                    (string) $year->getAttribute('slug') => [
                        'stage' => (string) $year->getAttribute('stage_slug'),
                        'name' => (string) $year->getAttribute('name'),
                    ],
                ])
                ->all(),
        );

        return $this->map = $map;
    }

    public function stageFor(?string $yearSlug): ?string
    {
        if ($yearSlug === null) {
            return null;
        }

        return $this->all()[$yearSlug]['stage'] ?? null;
    }

    public function nameFor(?string $yearSlug): ?string
    {
        if ($yearSlug === null) {
            return null;
        }

        return $this->all()[$yearSlug]['name'] ?? null;
    }
}
