<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;

/**
 * The marketplace is one global feed across every participating workspace (Q2=A).
 * These cases pin that behaviour so a later "fix" that re-applies tenant scoping
 * to the public path fails loudly instead of quietly emptying the site.
 */
it('merges teachers from many workspaces into a single ranked feed', function () {
    $workspaces = collect(['A', 'B', 'C', 'D'])
        ->map(fn (string $name) => marketplaceWorkspace("Academy {$name}"));

    $workspaces->each(fn ($workspace) => marketplaceTeacher($workspace, [
        'trust_score' => random_int(60, 95),
    ]));

    $this->asGuest();

    $response = $this->getJson('/api/v1/marketplace/teachers?sort=trust_desc');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(4);

    $scores = $response->json('data.*.trust_score');
    expect($scores)->toBe(collect($scores)->sortDesc()->values()->all());
});

it('never reveals which workspace a teacher belongs to', function () {
    marketplaceTeacher(marketplaceWorkspace('Very Distinctive Academy Name'));

    $this->asGuest();

    $body = $this->getJson('/api/v1/marketplace/teachers')->getContent();

    expect($body)->not->toContain('Very Distinctive Academy Name');
    expect($body)->not->toContain('workspace');
});

it('paginates across the combined feed', function () {
    $workspace = marketplaceWorkspace();

    app(WorkspaceContext::class)->forWorkspace($workspace, fn () => TeacherProfile::factory()
        ->published()
        ->scored()
        ->count(15)
        ->create(['workspace_id' => $workspace->getKey()]));

    $this->asGuest();

    $first = $this->getJson('/api/v1/marketplace/teachers?per_page=10');
    $second = $this->getJson('/api/v1/marketplace/teachers?per_page=10&page=2');

    expect($first->json('data'))->toHaveCount(10);
    expect($second->json('data'))->toHaveCount(5);
    expect($first->json('meta.last_page'))->toBe(2);

    // No overlap between pages — a wobbly sort would silently repeat rows.
    $firstUuids = $first->json('data.*.uuid');
    $secondUuids = $second->json('data.*.uuid');
    expect(array_intersect($firstUuids, $secondUuids))->toBeEmpty();
});

it('counts marketplace stats across all participating workspaces', function () {
    marketplaceTeacher(marketplaceWorkspace('Academy A'));
    marketplaceTeacher(marketplaceWorkspace('Academy B'));
    marketplaceTeacher(marketplaceWorkspace('Hidden', participates: false));

    $this->asGuest();

    $stats = $this->getJson('/api/v1/marketplace/stats')->json();

    expect($stats['teachers'])->toBe(2);
    expect($stats)->toHaveKeys(['students', 'teachers', 'sessions', 'satisfaction_rate']);
});

it('lists only subjects that have a publicly listed teacher', function () {
    $workspace = marketplaceWorkspace();
    $teacher = marketplaceTeacher($workspace);

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacher): void {
        $taught = Subject::query()->create([
            'workspace_id' => $workspace->getKey(), 'slug' => 'math', 'name_ar' => 'الرياضيات',
        ]);

        Subject::query()->create([
            'workspace_id' => $workspace->getKey(), 'slug' => 'astronomy', 'name_ar' => 'الفلك',
        ]);

        $teacher->subjects()->attach($taught->getKey());
    });

    $this->asGuest();

    $slugs = collect($this->getJson('/api/v1/marketplace/subjects')->json())->pluck('slug');

    expect($slugs)->toContain('math');
    // A tile leading to an empty result page is a dead end dressed as a start.
    expect($slugs)->not->toContain('astronomy');
});
