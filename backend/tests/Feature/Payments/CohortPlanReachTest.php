<?php

declare(strict_types=1);

use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Models\Plan;
use App\Shared\Contracts\CohortPricingReasonDirectory;
use App\Shared\Contracts\SellableCohortDirectory;
use App\Shared\Support\CohortPricingGap;

/*
| ٠٣٦ · T019…T023 — the price bridge, and above all the OVERRULE rule.
|
| ⛔ THE CASE THE WHOLE FILE EXISTS FOR IS «its own plan is unpriced». Written
| the obvious way — «own sellable OR inherited sellable» — that group quietly
| falls back on its course's price and is listed, and FR-014's middle reason
| («باقتها بانتظار التسعير») becomes a sentence no group on the platform can ever
| be in. The gate is the EXISTENCE of a plan of its own, not its sellability, and
| nothing but a fixture with both a private unpriced plan AND a live course plan
| can tell the two implementations apart.
|
| ⚠️ AND THE TENANT PIN NEEDS TWO WORKSPACES OR IT PROVES NOTHING. A
| workspace-wide plan carries a NULL coverage uuid, so an unpinned inheritance arm
| asks «is there ANY live workspace plan on the platform» — true for every group
| of every teacher. One workspace in a fixture cannot see that in any combination.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    $this->candidate = [[
        'id' => (int) $this->cohort->getKey(),
        'uuid' => (string) $this->cohort->uuid,
        'course_id' => (int) $this->course->getKey(),
        'workspace_id' => (int) $this->workspace->getKey(),
    ]];
});

/**
 * A group-sized plan of this teacher, covering whatever it is pointed at.
 *
 * `null` is workspace coverage — every course this teacher publishes — and it is
 * the shape with NO coverage uuid at all, which is why the tenant pin has to be
 * written by hand in the implementation and measured in the last case here.
 */
function reachGroupPlan(int $workspaceId, string $coverage, ?string $uuid = null, array $overrides = []): Plan
{
    $factory = match ($coverage) {
        'course' => Plan::factory()->group()->forCourse((string) $uuid),
        'cohort' => Plan::factory()->forCohort((string) $uuid),
        default => Plan::factory()->group(),
    };

    return $factory->create(array_merge(['workspace_id' => $workspaceId], $overrides));
}

function reachListedIds(array $candidates): array
{
    return app(SellableCohortDirectory::class)->sellableCohortIds($candidates);
}

function reachGapFor(array $candidates, int $cohortId): ?CohortPricingGap
{
    return app(CohortPricingReasonDirectory::class)->pricingGapsFor($candidates)[$cohortId] ?? null;
}

it('lists a group priced by a plan of its own', function (): void {
    reachGroupPlan((int) $this->workspace->getKey(), 'cohort', (string) $this->cohort->uuid);

    expect(reachListedIds($this->candidate))->toBe([(int) $this->cohort->getKey()])
        ->and(reachGapFor($this->candidate, (int) $this->cohort->getKey()))->toBeNull();
});

it('lists a group that inherits its course price', function (): void {
    reachGroupPlan((int) $this->workspace->getKey(), 'course', (string) $this->course->uuid);

    expect(reachListedIds($this->candidate))->toBe([(int) $this->cohort->getKey()]);
});

it('lists a group that inherits the teacher-wide price', function (): void {
    // Workspace coverage: no uuid at all, which is exactly why the tenant pin
    // below has to be written by hand.
    reachGroupPlan((int) $this->workspace->getKey(), 'workspace');

    expect(reachListedIds($this->candidate))->toBe([(int) $this->cohort->getKey()]);
});

it('refuses to let a group with an unpriced plan of its own inherit', function (): void {
    // The live course price is present ON PURPOSE. Without it this case is green
    // against an implementation that has no overrule rule in it at all.
    reachGroupPlan((int) $this->workspace->getKey(), 'course', (string) $this->course->uuid);
    reachGroupPlan((int) $this->workspace->getKey(), 'cohort', (string) $this->cohort->uuid, ['price_minor' => null]);

    expect(reachListedIds($this->candidate))->toBe([])
        ->and(reachGapFor($this->candidate, (int) $this->cohort->getKey()))->toBe(CohortPricingGap::AwaitingPricing);
});

it('names a switched-off plan as the reason, which the teacher can act on', function (): void {
    reachGroupPlan((int) $this->workspace->getKey(), 'course', (string) $this->course->uuid);
    reachGroupPlan((int) $this->workspace->getKey(), 'cohort', (string) $this->cohort->uuid, ['is_active' => false]);

    expect(reachListedIds($this->candidate))->toBe([])
        ->and(reachGapFor($this->candidate, (int) $this->cohort->getKey()))->toBe(CohortPricingGap::Disabled);
});

it('answers «no plan» when nothing covers the group', function (): void {
    expect(reachListedIds($this->candidate))->toBe([])
        ->and(reachGapFor($this->candidate, (int) $this->cohort->getKey()))->toBe(CohortPricingGap::NoPlan);
});

it('does not let a one-to-one price list a group', function (): void {
    // A room size is a price. Listed on an individual plan, the buyer picks the
    // group and the purchase door refuses it for a reason the screen never
    // mentioned — SC-007 broken from inside the gate that exists to keep it.
    Plan::factory()->forCourse((string) $this->course->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
        'session_type' => ClassSessionType::Individual,
    ]);

    expect(reachListedIds($this->candidate))->toBe([])
        ->and(reachGapFor($this->candidate, (int) $this->cohort->getKey()))->toBe(CohortPricingGap::NoPlan);
});

it('does not let another teacher plan list this group', function (): void {
    /*
     * ⛔ THE TENANT PIN, AND IT NEEDS THE SECOND WORKSPACE TO EXIST AT ALL.
     * `PlanReach` lifts the global scope deliberately — it is read from a public
     * page where the context is null and from a signed-in foreign teacher's
     * browser where the scope would hide every row — so the pin below is the
     * only thing left between one teacher's blanket plan and another teacher's
     * group.
     */
    [$otherWorkspace] = $this->createWorkspaceWithOwner();

    reachGroupPlan((int) $otherWorkspace->getKey(), 'workspace');

    expect(reachListedIds($this->candidate))->toBe([])
        ->and(reachGapFor($this->candidate, (int) $this->cohort->getKey()))->toBe(CohortPricingGap::NoPlan);
});

it('keeps two teachers apart inside ONE bulk call', function (): void {
    /*
     * ⛔ THE CASE ABOVE CANNOT SEE THE PIN, AND THAT IS WHY THIS ONE EXISTS.
     * With a single workspace among the candidates the SQL `whereIn` already
     * excludes every other teacher's rows, so deleting the workspace comparison
     * in PHP leaves that test green — measured. A bulk call spanning two
     * teachers is the only shape where both workspaces are in the query and the
     * comparison is the one thing keeping their plans apart: teacher B has a
     * blanket price, teacher A has none, and A's group must stay unlisted.
     */
    [$otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($otherWorkspace, $otherOwner);

    $otherCourse = courseWithRate((int) $otherWorkspace->getKey());
    $otherCourse->forceFill(['status' => 'published'])->save();

    $otherCohort = Cohort::factory()->create([
        'workspace_id' => $otherWorkspace->getKey(),
        'course_id' => $otherCourse->getKey(),
    ]);

    reachGroupPlan((int) $otherWorkspace->getKey(), 'workspace');

    $candidates = array_merge($this->candidate, [[
        'id' => (int) $otherCohort->getKey(),
        'uuid' => (string) $otherCohort->uuid,
        'course_id' => (int) $otherCourse->getKey(),
        'workspace_id' => (int) $otherWorkspace->getKey(),
    ]]);

    expect(reachListedIds($candidates))->toBe([(int) $otherCohort->getKey()])
        ->and(reachGapFor($candidates, (int) $this->cohort->getKey()))->toBe(CohortPricingGap::NoPlan);
});
