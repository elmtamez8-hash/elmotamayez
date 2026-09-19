<?php

declare(strict_types=1);

use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Support\CohortPricing;
use Database\Seeders\DemoDataSeeder;

/*
| ٠٣٦ · T101 — «migrate:fresh --seed» must not produce a product with no groups.
|
| ⛔ ZERO SEEDERS IN THIS TREE WROTE A `plans` ROW. The demo's three groups were
| seeded, listed and joinable for as long as the price gate did not exist — and
| the day it shipped they dropped out of the student's picker, out of the public
| course page and out of the join door at once. Nothing fails: the API answers
| 200 with an empty list, and the demo reads as a product that lists no groups.
|
| ⚠️ A SEEDER IS THE ONE FILE NOTHING ELSE EXERCISES — not a request, not a job,
| not another test — so it breaks silently and stays broken until somebody resets
| their database, which is the moment they least want to debug it.
|
| ⚠️ AND THE ASSERTION GOES THROUGH `CohortPricing`, THE SAME STAMP THE PICKER,
| THE PUBLIC PAGE AND THE JOIN DOOR ALL READ. Asserting that a `plans` row exists
| would be a claim about the seeder agreeing with itself; this is the question
| the product actually asks.
*/
it('seeds a live price that reaches the demo groups', function (): void {
    $this->seed(DemoDataSeeder::class);

    $cohorts = Cohort::query()
        ->withoutWorkspaceScope()
        ->group()
        ->where('status', '!=', Cohort::ARCHIVED)
        ->get();

    expect($cohorts)->not->toBeEmpty();

    $reached = collect(app(CohortPricing::class)->stamp($cohorts))
        ->filter(fn (Cohort $cohort): bool => $cohort->priceReaches());

    // ⛔ ALL OF THEM, not «at least one». The seeded plan covers the whole
    // workspace deliberately: a plan naming ONE group would stop the other two
    // inheriting — the overrule rule — and would seed the very defect this
    // guards against while looking like a fix.
    expect($reached->count())->toBe($cohorts->count());
});
