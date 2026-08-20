<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->workspace = marketplaceWorkspace('Academy');
});

/*
| Attach a taxonomy row to a teacher.
|
| ⚠️ firstOrCreate ON THE SLUG ALONE, because since spec 009 the taxonomy is
| PLATFORM reference data: there is one "math" row for the whole product and two
| teachers in two workspaces attach to the SAME one. These helpers used to create
| a fresh row per workspace, which is what "sharing a subject slug" below had to
| mean back when the slug was the only thing genuinely shared.
*/
function attachSubject(TeacherProfile $teacher, string $slug, string $name): void
{
    $subject = Subject::query()->firstOrCreate(['slug' => $slug], ['name_ar' => $name]);

    $teacher->subjects()->syncWithoutDetaching([$subject->getKey()]);
}

function attachGradeLevel(TeacherProfile $teacher, string $slug, string $name): void
{
    $level = GradeLevel::query()->firstOrCreate(['slug' => $slug], ['name_ar' => $name]);

    $teacher->gradeLevels()->syncWithoutDetaching([$level->getKey()]);
}

it('filters by subject slug rather than id', function () {
    $mathTeacher = marketplaceTeacher($this->workspace);
    $artTeacher = marketplaceTeacher($this->workspace);

    attachSubject($mathTeacher, 'math', 'الرياضيات');
    attachSubject($artTeacher, 'art', 'الفنون');

    $this->asGuest();

    $response = $this->getJson('/api/v1/marketplace/teachers?subject=math');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.uuid'))->toBe($mathTeacher->uuid);
});

it('finds teachers across workspaces sharing a subject slug', function () {
    $other = marketplaceWorkspace('Second Academy');

    attachSubject(marketplaceTeacher($this->workspace), 'math', 'الرياضيات');
    attachSubject(marketplaceTeacher($other), 'math', 'الرياضيات');

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers?subject=math')->json('meta.total'))->toBe(2);
});

/*
| ⚠️ These three used to assert the price filter, the range validation and the
| price sort — all shipped in 001, all retired by spec 006 (FR-021و).
|
| They are replaced rather than deleted, and by their own inverse: a test that
| merely disappears leaves nothing saying the behaviour was removed on purpose,
| and the next reader restores the filter as a missing feature. The full
| retirement is covered in PublicExposureTest; what these hold is the shape of
| the refusal at this endpoint.
*/
it('no longer filters by price, and says so with a 422', function () {
    marketplaceTeacher($this->workspace, ['hourly_rate' => 80]);
    marketplaceTeacher($this->workspace, ['hourly_rate' => 400]);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/teachers?price_min=100&price_max=200')
        ->assertStatus(422)
        ->assertJsonValidationErrors('price_min');

    // And the unfiltered list still answers — the refusal is about the
    // parameter, not about the endpoint.
    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(2);
});

it('rejects an unknown sort option', function () {
    $this->asGuest();

    $this->getJson('/api/v1/marketplace/teachers?sort=whatever')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

it('no longer sorts by price, and never publishes the rate it would have sorted on', function () {
    marketplaceTeacher($this->workspace, ['hourly_rate' => 300]);
    marketplaceTeacher($this->workspace, ['hourly_rate' => 90]);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/teachers?sort=price_asc')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');

    // The ordering is gone AND the field is gone. Either alone leaves a rate a
    // visitor can read: the sort without the field still ranks teachers by what
    // they charge, one request at a time.
    // `data.*.hourly_rate` yields one null per row when the key is absent — the
    // assertion is that no ROW carries a rate, not that no row came back.
    expect(array_filter($this->getJson('/api/v1/marketplace/teachers')->json('data.*.hourly_rate')))
        ->toBe([]);
});

it('ranks teachers still building a trust score below scored ones', function () {
    $scored = marketplaceTeacher($this->workspace, ['trust_score' => 70]);
    $building = marketplaceTeacher($this->workspace, ['trust_score' => null]);

    $this->asGuest();

    $order = $this->getJson('/api/v1/marketplace/teachers?sort=trust_desc')->json('data.*.uuid');

    expect($order)->toBe([$scored->uuid, $building->uuid]);
});

it('reports a building band instead of a zero score', function () {
    marketplaceTeacher($this->workspace, ['trust_score' => null]);

    $this->asGuest();

    $teacher = $this->getJson('/api/v1/marketplace/teachers')->json('data.0');

    expect($teacher['trust_score'])->toBeNull();
    expect($teacher['trust_score_band'])->toBe('building');
});

it('echoes applied filters back for removable chips', function () {
    marketplaceTeacher($this->workspace);

    $this->asGuest();

    $filters = $this->getJson('/api/v1/marketplace/teachers?subject=math&sort=trust_desc')->json('meta.filters');

    expect($filters)->toMatchArray(['subject' => 'math', 'sort' => 'trust_desc']);
});

it('caps per_page at the configured maximum', function () {
    $this->asGuest();

    $this->getJson('/api/v1/marketplace/teachers?per_page=500')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

it('matches only teachers inside an active availability window for available_now', function () {
    // The clock is pinned, and that is the whole point of this line.
    //
    // The window below is `now ± 1 hour`, and the query it must satisfy is
    // `day_of_week = today AND start_time <= now < end_time` — three columns
    // that all wrap at midnight while the window does not. Run this between
    // 00:00 and 01:00 UTC and `start_time` becomes 23:xx of the previous day,
    // so `start_time <= now` is false; run it in the last hour of the day and
    // `end_time` becomes 00:xx, so `end_time > now` is false. Two hours out of
    // every twenty-four the test fails against code that is correct, which is
    // the worst kind of red: it points at the wrong file.
    //
    // Midday on a fixed Wednesday sits an hour away from nothing. Laravel
    // restores the clock in tearDown, so no other test sees this.
    $this->travelTo(CarbonImmutable::parse('2026-08-05 12:00:00', 'UTC'));

    $available = marketplaceTeacher($this->workspace);
    marketplaceTeacher($this->workspace);

    $now = now('UTC');

    app(WorkspaceContext::class)->forWorkspace($this->workspace, fn () => AvailabilitySlot::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $available->getKey(),
        'day_of_week' => (int) $now->format('w'),
        'start_time' => $now->copy()->subHour()->format('H:i:s'),
        'end_time' => $now->copy()->addHour()->format('H:i:s'),
    ]));

    $this->asGuest();

    $response = $this->getJson('/api/v1/marketplace/teachers?available_now=1');

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.available_now'))->toBeTrue();
});

it('filters by grade level slug', function () {
    $teacher = marketplaceTeacher($this->workspace);
    marketplaceTeacher($this->workspace);

    app(WorkspaceContext::class)->forWorkspace($this->workspace, function () use ($teacher): void {
        $level = GradeLevel::query()->create([
            'slug' => 'secondary',
            'name_ar' => 'الثانوية',
        ]);

        $teacher->gradeLevels()->attach($level->getKey());
    });

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers?grade_level=secondary')->json('meta.total'))->toBe(1);
});

it('narrows the subject list to what is taught at a grade level', function () {
    // ⚠️ There is no subject↔grade_level table. The relation is DERIVED from the
    // teachers, so this is also the test that the derivation is real: فاطمة
    // teaches Arabic to primary, أحمد teaches maths to secondary, and asking for
    // primary must not return maths.
    $primary = marketplaceTeacher($this->workspace);
    attachSubject($primary, 'arabic', 'اللغة العربية');
    attachGradeLevel($primary, 'primary', 'المرحلة الابتدائية');

    $secondary = marketplaceTeacher($this->workspace);
    attachSubject($secondary, 'math', 'الرياضيات');
    attachGradeLevel($secondary, 'secondary', 'المرحلة الثانوية');

    $this->asGuest();

    $all = collect($this->getJson('/api/v1/marketplace/subjects')->json())->pluck('slug');
    expect($all)->toContain('arabic')->toContain('math');

    $forPrimary = collect(
        $this->getJson('/api/v1/marketplace/subjects?grade_level=primary')->json(),
    )->pluck('slug');

    expect($forPrimary)->toContain('arabic')->not->toContain('math');
});

it('caches the scoped subject list separately from the unscoped one', function () {
    // ⚠️ The regression this exists for: without the stage in the cache key, the
    // first request warms one list and every later visitor gets it whatever they
    // picked — a filter that looks like it works and answers the wrong question
    // for a whole TTL.
    $teacher = marketplaceTeacher($this->workspace);
    attachSubject($teacher, 'arabic', 'اللغة العربية');
    attachGradeLevel($teacher, 'primary', 'المرحلة الابتدائية');

    $this->asGuest();

    // Warm the SCOPED list first, then ask for a different stage.
    $this->getJson('/api/v1/marketplace/subjects?grade_level=primary')->assertOk();

    $secondary = marketplaceTeacher($this->workspace);
    attachSubject($secondary, 'math', 'الرياضيات');
    attachGradeLevel($secondary, 'secondary', 'المرحلة الثانوية');

    MarketplaceCache::flush();

    $forSecondary = collect(
        $this->getJson('/api/v1/marketplace/subjects?grade_level=secondary')->json(),
    )->pluck('slug');

    expect($forSecondary)->toContain('math')->not->toContain('arabic');
});
