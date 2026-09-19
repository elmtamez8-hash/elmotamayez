<?php

declare(strict_types=1);

use App\Modules\Learning\Models\Cohort;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| ٠٣٦ · T099 — the group picker's cost does not grow with the number of groups.
|
| ⛔ THE PRICE GATE IS A SECOND MODULE'S QUESTION ASKED ON A LIST, WHICH IS THE
| SHAPE OF EVERY N+1 THIS TREE HAS PAID FOR. `CohortPlanReach` matches groups to
| plans in PHP over a fixed number of result sets precisely so that the answer
| costs the same for two groups and for twenty; written the obvious way — one
| `exists()` per row inside the Resource — it is the `ClassSessionResource`
| defect arriving through the door this spec opened.
|
| ⚠️ TWO FIXTURES OF DIFFERENT SIZES, NEVER ONE. A fixed ceiling over a fixture
| of two passes against an implementation that asks per row, and an equality at
| one size measures the implementation against itself.
|
| ⛔ AND THE COVERED GROUP IS ASSERTED **PRESENT** ALONGSIDE THE COUNT. The gate
| DROPS an unlisted group rather than flagging it, so a bridge that answered «no
| group is listed» hides everything at a perfectly flat cost — and a budget that
| measured queries alone would report that as an improvement. The two assertions
| guard the two opposite mistakes.
|
| ⚠️ AND IT IS WARMED UNTIL STEADY. spatie's permission cache and the
| `platform_settings` rows this path reads are filled by the first requests in
| the process, so a single warm-up leaves a query or two of fill in the
| measurement — the bigger page then looks cheaper by a fixed handful and a small
| N+1 hides in the difference. `QueryBudgetTest`'s presence case cost this tree a
| flake of exactly one query, measured rather than guessed.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array{0: int, 1: list<string>} the query count, and the names offered */
function measurePicker(object $test, int $groups): array
{
    $fx = cohortFixture();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $groups): void {
        for ($i = 0; $i < $groups; $i++) {
            Cohort::factory()->create([
                'workspace_id' => $fx['workspace']->getKey(),
                'course_id' => $fx['course']->getKey(),
                'created_by' => $fx['owner']->getKey(),
                'name' => 'مجموعة رقم '.$i,
            ]);
        }

        /*
        | ⚠️ ONE GROUP WITH A PLAN OF ITS OWN, IN EVERY FIXTURE SIZE. The overrule
        | rule is the expensive branch — it asks a second question about the
        | groups that carry their own plan — so a fixture where every group
        | inherits measures only the cheap half.
        */
        Plan::factory()->group()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'coverage_type' => PlanCoverage::Cohort,
            'coverage_uuid' => (string) $fx['b']->uuid,
        ]);
    });

    Sanctum::actingAs($fx['student']);
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    $url = '/api/v1/courses/'.$fx['course']->uuid.'/cohorts';

    // Warmed TWICE, until steady: one request does not touch every
    // `platform_settings` key or every permission this path reads.
    $test->getJson($url)->assertOk();
    $test->getJson($url)->assertOk();

    [$count, $response] = countingQueries(fn () => $test->getJson($url)->assertOk());

    return [$count, array_column($response->json('cohorts'), 'name')];
}

it('answers two groups and twenty in the same number of queries, and still lists the covered ones', function (): void {
    [$small, $smallNames] = measurePicker($this, 0);
    [$large, $largeNames] = measurePicker($this, 18);

    expect($large)->toBe($small);

    /*
    | ⛔ THE POSITIVE HALF. `cohortFixture()` writes a workspace-wide group price,
    | so every group it builds is covered — and «السبت ٤م» being in the list is
    | what stops this file from passing over a contract that answers an empty
    | array at a beautifully flat cost.
    */
    expect($smallNames)->toContain('السبت ٤م')
        ->and($smallNames)->toHaveCount(2)
        ->and($largeNames)->toContain('السبت ٤م')
        ->and($largeNames)->toHaveCount(20);
});
