<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;

beforeEach(function () {
    $this->workspace = marketplaceWorkspace('Academy');
});

/** Attach a taxonomy row (created in the workspace) to a teacher. */
function attachSubject(TeacherProfile $teacher, string $slug, string $name): void
{
    app(WorkspaceContext::class)->forWorkspace($teacher->workspace_id, function () use ($teacher, $slug, $name): void {
        $subject = Subject::query()->create([
            'workspace_id' => $teacher->workspace_id,
            'slug' => $slug,
            'name_ar' => $name,
        ]);

        $teacher->subjects()->attach($subject->getKey());
    });
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

it('filters by price range', function () {
    marketplaceTeacher($this->workspace, ['hourly_rate' => 80]);
    marketplaceTeacher($this->workspace, ['hourly_rate' => 150]);
    marketplaceTeacher($this->workspace, ['hourly_rate' => 400]);

    $this->asGuest();

    $response = $this->getJson('/api/v1/marketplace/teachers?price_min=100&price_max=200');

    expect($response->json('meta.total'))->toBe(1);
});

it('rejects a price range whose maximum is below its minimum', function () {
    $this->asGuest();

    $this->getJson('/api/v1/marketplace/teachers?price_min=300&price_max=100')
        ->assertStatus(422)
        ->assertJsonValidationErrors('price_max');
});

it('rejects an unknown sort option', function () {
    $this->asGuest();

    $this->getJson('/api/v1/marketplace/teachers?sort=whatever')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

it('sorts by price ascending', function () {
    marketplaceTeacher($this->workspace, ['hourly_rate' => 300]);
    marketplaceTeacher($this->workspace, ['hourly_rate' => 90]);

    $this->asGuest();

    $prices = $this->getJson('/api/v1/marketplace/teachers?sort=price_asc')->json('data.*.hourly_rate');

    expect($prices)->toBe(['90.00', '300.00']);
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

    $filters = $this->getJson('/api/v1/marketplace/teachers?subject=math&sort=price_asc')->json('meta.filters');

    expect($filters)->toMatchArray(['subject' => 'math', 'sort' => 'price_asc']);
});

it('caps per_page at the configured maximum', function () {
    $this->asGuest();

    $this->getJson('/api/v1/marketplace/teachers?per_page=500')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

it('matches only teachers inside an active availability window for available_now', function () {
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
            'workspace_id' => $this->workspace->getKey(),
            'slug' => 'secondary',
            'name_ar' => 'الثانوية',
        ]);

        $teacher->gradeLevels()->attach($level->getKey());
    });

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers?grade_level=secondary')->json('meta.total'))->toBe(1);
});
