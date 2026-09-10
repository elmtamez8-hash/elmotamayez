<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use Database\Seeders\TaxonomySeeder;

/*
| The taxonomy reaches EVERY database, not only a demo one (spec 022 · R2).
|
| ⚠️ THE FIXTURE IS THE TEST. `RefreshDatabase` runs the migrations and nothing
| else, and the two backfill migrations are part of that — so what these cases
| read is a database with no demo data at all, which is what a production deploy
| looks like. Before this spec the vocabulary was written only by
| `MarketplaceSeeder`, which `DatabaseSeeder` calls inside
| `if (! app()->environment('production'))`.
*/

it('holds the full vocabulary on a database with no demo data', function (): void {
    // Not an absolute count: rows here are platform reference data and anything
    // is entitled to add one — `backfill_course_subject` already writes
    // `general`. What is asserted is that the named slugs are all present.
    $subjects = Subject::query()->pluck('slug');

    expect($subjects)->toContain('math', 'science', 'arabic', 'computer-science');

    $stages = GradeLevel::query()->pluck('slug');

    expect($stages)->toContain('kindergarten', 'primary', 'preparatory', 'secondary', 'university');

    $years = SchoolYear::query()->pluck('slug');

    expect($years)->toContain('kindergarten', 'year-1', 'year-6', 'year-7', 'year-12', 'university')
        ->and($years)->toHaveCount(14);
});

it('holds the vocabulary while zero teachers are publicly listed', function (): void {
    // The circular lock, stated as a fixture: the vocabulary must exist BEFORE
    // anybody is listed, or the first teacher can never apply.
    expect(TeacherProfile::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(Subject::query()->where('is_active', true)->count())->toBeGreaterThan(0)
        ->and(GradeLevel::query()->where('is_active', true)->count())->toBeGreaterThan(0);
});

it('gives every school year a stage', function (): void {
    // FR-011أ, at the database rather than only in the form: a year with no
    // stage cannot answer the one question every existing reader asks.
    expect(SchoolYear::query()->whereNull('grade_level_id')->count())->toBe(0);

    $orphans = SchoolYear::query()
        ->whereNotIn('grade_level_id', GradeLevel::query()->select('id'))
        ->count();

    expect($orphans)->toBe(0);
});

it('keeps an operator rename when the deploy path runs again', function (): void {
    // SC-006. `seedMissing()` is `firstOrCreate`, so a row an operator edited
    // from /admin survives the next release. `run()` would not — which is why
    // the two backfill migrations call this method and never that one.
    // Through the MODEL, as /admin does: `name` is a translatable JSON column
    // and a bulk `update()` applies no cast, so the raw string would land in the
    // document and read back as nothing at all.
    Subject::query()->where('slug', 'math')->firstOrFail()->update(['name' => 'الرياضيات المتقدمة']);
    $before = Subject::query()->count();

    (new TaxonomySeeder)->seedMissing();

    expect(Subject::query()->where('slug', 'math')->value('name'))->toBe('الرياضيات المتقدمة')
        ->and(Subject::query()->count())->toBe($before);
});

it('overwrites an edit in development mode, and only there', function (): void {
    // The other half of the two-mode contract: `run()` is `migrate:fresh --seed`
    // and is what makes a developer's database match the constants.
    Subject::query()->where('slug', 'math')->firstOrFail()->update(['name' => 'شيء آخر']);

    (new TaxonomySeeder)->run();

    expect(Subject::query()->where('slug', 'math')->value('name'))->toBe('الرياضيات');
});
