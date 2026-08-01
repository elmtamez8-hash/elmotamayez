<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Cache;

/**
 * Subjects or grade levels that have at least one publicly listed teacher.
 *
 * Rows are tenant-owned but the slug is a platform vocabulary, so results are
 * folded by slug: three workspaces teaching maths produce one "الرياضيات" entry
 * with the teacher counts summed.
 *
 * Entries with no teachers are dropped — a filter option that can only ever
 * return an empty page is a dead end dressed up as a starting point.
 *
 * One action serves both taxonomies because the query is identical; the models
 * differ only in table name.
 */
class ListPublicTaxonomy extends Action
{
    public const SUBJECTS = 'subjects';

    public const GRADE_LEVELS = 'grade_levels';

    /** @return list<array{slug: string, name_ar: string, icon: string|null, teachers_count: int}> */
    public function handle(string $taxonomy = self::SUBJECTS): array
    {
        /** @var class-string<Subject|GradeLevel> $model */
        $model = $taxonomy === self::GRADE_LEVELS ? GradeLevel::class : Subject::class;

        return Cache::remember(
            MarketplaceCache::key($taxonomy),
            MarketplaceCache::ttl(),
            function () use ($model): array {
                $rows = $model::query()
                    ->withoutWorkspaceScope()
                    ->where('is_active', true)
                    ->withCount(['teacherProfiles as teachers_count' => fn ($query) => $query->publiclyListed()])
                    ->orderBy('sort_order')
                    ->get();

                /** @var array<string, array{slug: string, name_ar: string, icon: string|null, teachers_count: int}> $folded */
                $folded = [];

                foreach ($rows as $row) {
                    $count = (int) $row->getAttribute('teachers_count');

                    if ($count === 0) {
                        continue;
                    }

                    $slug = $row->slug;

                    if (isset($folded[$slug])) {
                        $folded[$slug]['teachers_count'] += $count;

                        continue;
                    }

                    $icon = $row->getAttribute('icon');

                    $folded[$slug] = [
                        'slug' => $slug,
                        'name_ar' => $row->name_ar,
                        'icon' => is_string($icon) ? $icon : null,
                        'teachers_count' => $count,
                    ];
                }

                return array_values($folded);
            },
        );
    }
}
