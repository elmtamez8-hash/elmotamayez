<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * The subjects, the broad stages and the individual school years every signup
 * form offers (spec 022 · FR-001…FR-005).
 *
 * ⚠️ THIS IS AN EXTRACTION, NOT A NEW CATALOGUE. Both constants lived in
 * `MarketplaceSeeder`, which `DatabaseSeeder` calls INSIDE
 * `if (! app()->environment('production'))` — so a production database was born
 * with an empty taxonomy, the teacher application offered no subject to pick,
 * and the first teacher on the platform could never apply. Seeded here, called
 * unconditionally, and backfilled onto databases that already exist by the two
 * migrations under `Modules/Marketplace/Database/Migrations`.
 *
 * ⚠️ TWO MODES, exactly as `RegionSeeder`, `NotificationTemplateSeeder`,
 * `DataCategorySeeder` and `GamificationCatalogSeeder`. `run()` overwrites and
 * belongs to `migrate:fresh --seed`; `seedMissing()` adds only what is absent and
 * is the ONLY mode a deploy may call — every row here is editable from `/admin`,
 * and an `updateOrCreate` in the deploy path resets an operator's renaming and
 * reordering on every single release.
 *
 * ⚠️ `sort_order` IS WRITTEN AS AN EXPLICIT VALUE, never an array index.
 * `firstOrCreate` writes it for NEW rows only, so an index-derived order would
 * make production's ordering differ from development's the moment a row is
 * inserted in the middle of a list.
 */
class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $this->write(overwrite: true);
    }

    public function seedMissing(): void
    {
        $this->write(overwrite: false);
    }

    private function write(bool $overwrite): void
    {
        foreach (self::SUBJECTS as [$slug, $name, $icon, $order]) {
            $this->upsert(Subject::query(), $overwrite, ['slug' => $slug], [
                'name_ar' => $name,
                'icon' => $icon,
                'sort_order' => $order,
                'is_active' => true,
            ]);
        }

        foreach (self::GRADE_LEVELS as [$slug, $name, $order]) {
            $this->upsert(GradeLevel::query(), $overwrite, ['slug' => $slug], [
                'name_ar' => $name,
                'sort_order' => $order,
                'is_active' => true,
            ]);
        }

        $this->writeSchoolYears($overwrite);
    }

    /**
     * ⚠️ GUARDED BY `hasTable`, AND THE GUARD IS NOT DEFENSIVE PADDING.
     *
     * Laravel orders migrations by filename, and the taxonomy backfill
     * (`2026_08_31_000100`) deliberately predates `create_school_years`
     * (`2026_09_01_000100`) — the subjects and stages have to reach a live
     * database whether or not the new table ever lands. Without this guard that
     * first backfill would write to a table that does not exist yet and every
     * `RefreshDatabase` test in the suite would throw. Nothing is lost: the second
     * backfill (`2026_09_01_000200`) calls `seedMissing()` again once the table is
     * there.
     */
    private function writeSchoolYears(bool $overwrite): void
    {
        if (! Schema::hasTable('school_years')) {
            return;
        }

        // Stages first — a year with no stage cannot be written at all
        // (`grade_level_id` is NOT NULL), and the loop above has just run.
        $stages = GradeLevel::query()->pluck('id', 'slug');

        foreach (self::SCHOOL_YEARS as [$slug, $name, $stageSlug, $order]) {
            // ⚠️ THROWS RATHER THAN SKIPPING. The backfill migration runs ONCE
            // and `firstOrCreate` never comes back for a row it did not write, so
            // a year skipped here is a year missing from the picker for the life
            // of the deployment — silently. A stage that is absent is a bug in
            // this file, and it should stop the deploy.
            if (! isset($stages[$stageSlug])) {
                throw new \RuntimeException(
                    "TaxonomySeeder: school year [{$slug}] names an unknown stage [{$stageSlug}].",
                );
            }

            $this->upsert(SchoolYear::query(), $overwrite, ['slug' => $slug], [
                'grade_level_id' => $stages[$stageSlug],
                'name_ar' => $name,
                'sort_order' => $order,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(Builder $query, bool $overwrite, array $key, array $attributes): void
    {
        if ($overwrite) {
            $query->updateOrCreate($key, $attributes);

            return;
        }

        $query->firstOrCreate($key, $attributes);
    }

    /**
     * The nine that shipped in 009 plus the four the Qatari curriculum needs.
     *
     * `science` matters most of the four: primary and preparatory do not split
     * physics from chemistry, so without it every teacher of those stages had to
     * pick a subject they do not actually teach.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private const SUBJECTS = [
        ['math', 'الرياضيات', 'calculator', 10],
        ['science', 'العلوم', 'beaker', 20],
        ['physics', 'الفيزياء', 'beaker', 30],
        ['chemistry', 'الكيمياء', 'beaker', 40],
        ['biology', 'الأحياء', 'beaker', 50],
        ['arabic', 'اللغة العربية', 'book-open', 60],
        ['english', 'اللغة الإنجليزية', 'language', 70],
        ['french', 'اللغة الفرنسية', 'language', 80],
        ['islamic-studies', 'التربية الإسلامية', 'book-open', 90],
        ['social-studies', 'الاجتماعيات', 'book-open', 100],
        ['history', 'التاريخ', 'book-open', 110],
        ['geography', 'الجغرافيا', 'book-open', 120],
        ['computer-science', 'الحاسب الآلي', 'computer-desktop', 130],
    ];

    /**
     * The broad stages a TEACHER picks — four existing slugs plus one.
     *
     * ⚠️ THE FOUR EXISTING SLUGS ARE NOT TOUCHED, and that is a money decision.
     * `courses.grade_level` is undefended text carrying these values, the
     * leaderboard key is `grade:{slug}`, and a teacher's settlement rate is keyed
     * on `(subject, grade_level)` — `RequestRateChange` looks a rate up by it and
     * `AccrueTeachingUnits` reads it. Renaming one of these slugs reaches the
     * teacher's pay.
     *
     * @var list<array{0: string, 1: string, 2: int}>
     */
    private const GRADE_LEVELS = [
        ['kindergarten', 'رياض الأطفال', 10],
        ['primary', 'المرحلة الابتدائية', 20],
        ['preparatory', 'المرحلة الإعدادية', 30],
        ['secondary', 'المرحلة الثانوية', 40],
        ['university', 'المرحلة الجامعية', 50],
    ];

    /**
     * The individual years a STUDENT picks, and the stage each belongs to.
     *
     * ⚠️ `year-`, NEVER `grade-`. The three text columns above carry a STAGE
     * slug and no database constraint defends any of them, so a value spelled
     * `grade-10` would sit in one of them looking perfectly plausible. The English
     * word in the value is what says which vocabulary a slug belongs to.
     *
     * `university` appearing in both lists is deliberate and collides with
     * nothing — two separate tables. A university student has no school year, so
     * their own stage is the only sensible option in a picker of years.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private const SCHOOL_YEARS = [
        ['kindergarten', 'الروضة والتمهيدي', 'kindergarten', 10],
        ['year-1', 'الصف الأول الابتدائي', 'primary', 20],
        ['year-2', 'الصف الثاني الابتدائي', 'primary', 30],
        ['year-3', 'الصف الثالث الابتدائي', 'primary', 40],
        ['year-4', 'الصف الرابع الابتدائي', 'primary', 50],
        ['year-5', 'الصف الخامس الابتدائي', 'primary', 60],
        ['year-6', 'الصف السادس الابتدائي', 'primary', 70],
        ['year-7', 'الصف السابع', 'preparatory', 80],
        ['year-8', 'الصف الثامن', 'preparatory', 90],
        ['year-9', 'الصف التاسع', 'preparatory', 100],
        ['year-10', 'الصف العاشر', 'secondary', 110],
        ['year-11', 'الصف الحادي عشر', 'secondary', 120],
        ['year-12', 'الصف الثاني عشر', 'secondary', 130],
        ['university', 'المرحلة الجامعية', 'university', 140],
    ];
}
