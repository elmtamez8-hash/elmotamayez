<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Support\CohortPricing;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/*
| ٠٣٦ · T045 · T120 — «يصلُها ثمنٌ» يُقرَأُ من ختمٍ، ولا يسألُ أبداً.
|
| ⛔ THE FALL-BACK IS THE DEFECT, AND IT IS THE ONE ANYBODY WOULD ADD. A
| `priceReaches()` that quietly asked `plans` when nobody had stamped the row
| would be correct on every screen and wrong in two ways at once: it fires that
| question ONCE PER ROW inside a Resource — an N+1 by construction, the
| `ClassSessionResource` defect through yet another door — and it fires it under
| whatever workspace context the caller happens to be in, which in a queue worker
| is somebody else's.
|
| ⚠️ AND THE «NO QUERY» HALF IS A SEPARATE ASSERTION FROM THE THROW, because a
| fall-back that asked first and threw afterwards would satisfy the throw. The
| whole point is that nothing is asked.
|
| ⚠️ T099's QUERY BUDGET CANNOT SEE ANY OF THIS: its fixture goes through the
| directory, so every row it touches is stamped. This is the case for the row
| that did not.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn () => Course::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->getKey(),
        ]),
    );

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
    ]);
});

it('raises rather than guessing when nobody stamped the row', function (): void {
    // Straight out of a query, which is exactly how the defect arrives: a model
    // fetched by a screen that forgot to ask the directory for it.
    $fresh = Cohort::query()->withoutWorkspaceScope()->whereKey($this->cohort->getKey())->firstOrFail();

    expect($fresh->hasPriceStamp())->toBeFalse();

    $fresh->priceReaches();
})->throws(RuntimeException::class, 'without a stamp');

it('asks nothing on the way to raising', function (): void {
    $fresh = Cohort::query()->withoutWorkspaceScope()->whereKey($this->cohort->getKey())->firstOrFail();

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $fresh->priceReaches();
    } catch (RuntimeException) {
        // The sentence is measured above; this case is about the wire.
    }

    // ⛔ ZERO. One query here is one per row of every list in the product.
    expect(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});

it('answers from the stamp once the directory has put one on', function (): void {
    /*
    | ⚠️ THE POSITIVE CONTROL, AND WITHOUT IT THE TWO CASES ABOVE ARE EQUALLY
    | TRUE OF A METHOD THAT ONLY EVER THROWS. `stamp()` is the one spelling every
    | reader goes through — the public page, the picker, the teacher's list and
    | the single-row responses — so this is also the assertion that the stamp
    | reaches the row it was asked about.
    */
    $fresh = Cohort::query()->withoutWorkspaceScope()->whereKey($this->cohort->getKey())->firstOrFail();

    app(CohortPricing::class)->stampOne($fresh);

    expect($fresh->hasPriceStamp())->toBeTrue()
        // No plan reaches it, so the answer is false — and it is an ANSWER now,
        // not an exception.
        ->and($fresh->priceReaches())->toBeFalse();

    groupPriceFor($this->course);

    expect(app(CohortPricing::class)->stampOne($fresh)->priceReaches())->toBeTrue();
});
