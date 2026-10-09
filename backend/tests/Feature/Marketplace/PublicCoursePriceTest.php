<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;

/*
| The price on the course's own page (review 2026-10-09: it first appeared at the
| last step of checkout). Read from the plans the buyer would be offered —
| `ListPlans`'s choice — and only over a door that is open.
*/
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace();
    $this->teacher = marketplaceTeacher($this->workspace);

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->user_id,
        ]),
    );

    $this->asGuest();
});

function pricedPage(Course $course): array
{
    return test()->getJson('/api/v1/marketplace/courses/'.$course->uuid)->json('data');
}

function pricedGroup(Course $course, array $attributes = []): Cohort
{
    return Cohort::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'created_by' => $course->created_by,
        ...$attributes,
    ]);
}

function groupPlan(Course $course, int $price, array $attributes = []): Plan
{
    return Plan::factory()->group()->create([
        'workspace_id' => $course->workspace_id,
        'price_minor' => $price,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $course->uuid,
        ...$attributes,
    ]);
}

it('prices a joinable group from the cheapest course plan, and heads the page with it', function (): void {
    groupPlan($this->course, 45_000);
    groupPlan($this->course, 30_000, ['duration_days' => null, 'session_count' => 8]);
    $group = pricedGroup($this->course);

    $page = pricedPage($this->course);

    $cheapest = ['price_minor' => 30_000, 'currency' => 'QAR', 'duration_days' => null, 'session_count' => 8];

    expect(collect($page['cohorts'])->firstWhere('uuid', (string) $group->uuid)['price'])->toBe($cheapest)
        ->and($page['starting_price'])->toBe($cheapest)
        ->and($page['private_price'])->toBeNull();
});

it('prices a group by its OWN plans when it has any, as the buyer is offered', function (): void {
    groupPlan($this->course, 30_000);
    $own = pricedGroup($this->course, ['name' => 'مسائية']);
    $plain = pricedGroup($this->course, ['name' => 'صباحية']);
    groupPlan($this->course, 60_000, ['coverage_type' => PlanCoverage::Cohort, 'coverage_uuid' => $own->uuid]);

    $prices = collect(pricedPage($this->course)['cohorts'])->pluck('price', 'uuid');

    expect($prices[(string) $own->uuid]['price_minor'])->toBe(60_000)
        ->and($prices[(string) $plain->uuid]['price_minor'])->toBe(30_000);
});

it('shows no price over a group whose own plans are switched off — it is sold nothing', function (): void {
    groupPlan($this->course, 30_000);
    $group = pricedGroup($this->course);
    groupPlan($this->course, 60_000, [
        'coverage_type' => PlanCoverage::Cohort,
        'coverage_uuid' => $group->uuid,
        'is_active' => false,
    ]);

    $card = collect(pricedPage($this->course)['cohorts'])->firstWhere('uuid', (string) $group->uuid);

    expect($card === null || $card['price'] === null)->toBeTrue();
});

it('shows no price over a full group, nor a header price when nothing is open', function (): void {
    groupPlan($this->course, 30_000);
    $full = pricedGroup($this->course, ['capacity' => 1, 'members_count' => 1]);

    $page = pricedPage($this->course);

    // The positive control: the group IS on the page, just not for sale.
    expect(collect($page['cohorts'])->firstWhere('uuid', (string) $full->uuid)['price'])->toBeNull()
        ->and($page)->toHaveKey('starting_price')
        ->and($page['starting_price'])->toBeNull();
});

it('prices the private hours only when the invitation is drawn', function (): void {
    Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 90_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    // A priced plan but no declared hours: the invitation is not drawn, so no price.
    expect(pricedPage($this->course)['private_price'])->toBeNull();

    AvailabilitySlot::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $this->teacher->getKey(),
    ]);

    $page = pricedPage($this->course);

    expect($page['private_price']['price_minor'])->toBe(90_000)
        ->and($page['starting_price']['price_minor'])->toBe(90_000);
});

it('never prices from an unpriced or inactive plan', function (): void {
    Plan::factory()->group()->unpriced()->create([
        'workspace_id' => $this->workspace->getKey(),
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);
    groupPlan($this->course, 10_000, ['is_active' => false]);
    groupPlan($this->course, 50_000);
    pricedGroup($this->course);

    expect(pricedPage($this->course)['starting_price']['price_minor'])->toBe(50_000);
});
