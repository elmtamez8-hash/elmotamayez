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
 *
 * ⚠️ SUBJECTS CAN BE SCOPED TO A GRADE LEVEL, AND THE RELATION IS DERIVED.
 * There is no `subject_grade_level` pivot and this does not invent one: the
 * subjects offered at a stage are the subjects that publicly listed teachers
 * OF THAT STAGE actually teach. Same rule as the rest of the module — nothing
 * on a public surface that the data cannot produce. A stored table of "which
 * subjects belong to secondary" would be a second answer that drifts from the
 * teachers the day one of them adds a stage.
 */
class ListPublicTaxonomy extends Action
{
    public const SUBJECTS = 'subjects';

    public const GRADE_LEVELS = 'grade_levels';

    /**
     * @param  string|null  $gradeLevel  Slug. Narrows SUBJECTS to those taught at
     *                                   that stage; ignored for grade levels,
     *                                   which are the axis being scoped by.
     * @return list<array{slug: string, name_ar: string, icon: string|null, teachers_count: int}>
     */
    public function handle(string $taxonomy = self::SUBJECTS, ?string $gradeLevel = null): array
    {
        /** @var class-string<Subject|GradeLevel> $model */
        $model = $taxonomy === self::GRADE_LEVELS ? GradeLevel::class : Subject::class;

        if ($taxonomy === self::GRADE_LEVELS) {
            $gradeLevel = null;
        }

        return Cache::remember(
            // ⚠️ The stage is part of the KEY. Without it the first request warms
            // the cache with one stage's subjects and every later visitor gets
            // that stage's list whatever they picked — a filter that looks like
            // it works and answers the wrong question for an hour.
            MarketplaceCache::key($taxonomy.($gradeLevel === null ? '' : ':grade='.$gradeLevel)),
            MarketplaceCache::ttl(),
            function () use ($model, $gradeLevel): array {
                $rows = $model::query()
                    ->withoutWorkspaceScope()
                    ->where('is_active', true)
                    ->withCount([
                        'teacherProfiles as teachers_count' => fn ($query) => $query
                            ->publiclyListed()
                            ->when(
                                $gradeLevel !== null,
                                fn ($scoped) => $scoped->whereHas(
                                    'gradeLevels',
                                    fn ($levels) => $levels->where('grade_levels.slug', $gradeLevel),
                                ),
                            ),
                    ])
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
