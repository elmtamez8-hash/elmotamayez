<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/*
| Spec 027 · FR-002 · FR-003 — the two fields the subscribe buttons are drawn from.
|
| ⚠️ `PublicExposureTest` CANNOT COVER THIS, AND ITS GREEN IS THE TRAP. That test
| walks the payload against `PublicFieldAllowlist` and fails on a key that is NOT
| listed — so it stays green when a key is MISSING ENTIRELY, which is the exact
| regression that would silently remove every subscribe button from the product.
| Its fixture also builds a course with no groups at all, so the whole COHORT
| shape goes unwalked there.
*/
beforeEach(function (): void {
    /*
    | ⚠️ `marketplaceWorkspace()`, NOT `createWorkspaceWithOwner()`. The public
    | listing asks `participates_in_marketplace`, and a workspace that has not
    | opted in answers 404 to every request below — which reads as the fields
    | being absent rather than as the fixture being wrong.
    */
    $this->workspace = marketplaceWorkspace();
    // The teacher builds their own user: `is_publicly_listed` is DERIVED from
    // approval plus workspace participation, and forcing an existing user id
    // onto the profile produces a teacher the public listing refuses — at which
    // point the endpoint answers 404 and every assertion below is vacuous.
    $this->teacher = marketplaceTeacher($this->workspace);

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->user_id,
        ]),
    );

    /*
    | ⚠️ `asGuest()` BEFORE ANY REQUEST. `WorkspaceContext` freezes on its first
    | resolution and the fixtures above resolved it to the teacher's workspace;
    | a visitor to a public page has no workspace at all, and reading this page
    | with one is measuring somebody who is not the audience.
    */
    $this->asGuest();
});

function doorPayload(Course $course): array
{
    return test()->getJson('/api/v1/marketplace/courses/'.$course->uuid)->json('data');
}

function cohortAt(Course $course, array $attributes = []): Cohort
{
    return Cohort::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'created_by' => $course->created_by,
        ...$attributes,
    ]);
}

it('answers is_joinable per group, with the door’s own predicate', function (): void {
    /*
    | ⚠️ THE SERVER ANSWERS IT (FR-002). Derived in the browser from `status` and
    | `seats_left`, one question would have two spellings — the card saying yes
    | while the purchase route says no, which is what makes a pressed button a
    | 422 the visitor cannot act on.
    */
    $open = cohortAt($this->course, ['name' => 'مفتوحة']);
    $full = cohortAt($this->course, ['name' => 'ممتلئة', 'capacity' => 1, 'members_count' => 1]);
    $closed = cohortAt($this->course, ['name' => 'مغلقة', 'status' => Cohort::CLOSED]);

    $cohorts = collect(doorPayload($this->course)['cohorts'])->keyBy('uuid');

    $at = fn (Cohort $cohort): array => $cohorts[(string) $cohort->uuid];

    expect($at($open)['is_joinable'])->toBeTrue()
        ->and($at($full)['is_joinable'])->toBeFalse()
        ->and($at($closed)['is_joinable'])->toBeFalse();
});

it('keeps is_joinable PRESENT on every card, not merely allowlisted', function (): void {
    cohortAt($this->course);

    foreach (doorPayload($this->course)['cohorts'] as $cohort) {
        expect($cohort)->toHaveKey('is_joinable');
    }
});

it('does not publish a private one-to-one group on the public page', function (): void {
    // Opened by accident or on purpose, it is still another named student's room.
    cohortAt($this->course, [
        'individual_for_user_id' => $this->teacher->user_id,
        'capacity' => 1,
        'status' => Cohort::OPEN,
    ]);

    expect(doorPayload($this->course)['cohorts'])->toBe([]);
});

it('offers private subscription only with declared hours AND a priced individual plan', function (): void {
    // Neither yet.
    expect(doorPayload($this->course)['private_subscription_available'])->toBeFalse();

    AvailabilitySlot::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $this->teacher->getKey(),
    ]);

    // Hours but nothing to buy — a button here is pressed and then refused.
    expect(doorPayload($this->course)['private_subscription_available'])->toBeFalse();

    Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 90_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    expect(doorPayload($this->course)['private_subscription_available'])->toBeTrue();
});

it('does not offer private subscription on a GROUP plan alone', function (): void {
    AvailabilitySlot::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $this->teacher->getKey(),
    ]);

    Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    expect(doorPayload($this->course)['private_subscription_available'])->toBeFalse();
});

it('says nothing about an unpriced plan, and says it the same way as no plan at all', function (): void {
    /*
    | ⚠️ THE COLLAPSE IS THE PRIVACY PROPERTY. `PurchaseSubscription` answers «no
    | plan», «switched off» and «awaiting a price» with one sentence so that
    | nobody learns which teachers have a plan waiting to be priced. This field
    | has to collapse them the same way, or the public page becomes the oracle
    | the refusal was written to avoid.
    */
    AvailabilitySlot::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $this->teacher->getKey(),
    ]);

    Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => null,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    expect(doorPayload($this->course)['private_subscription_available'])->toBeFalse();
});

it('costs the same number of queries whatever the number of groups', function (): void {
    /*
    | ⚠️ THIS ENDPOINT IS DELIBERATELY UNCACHED and is now the funnel entry for
    | every purchase in the product. `is_joinable` is derived from columns the
    | group read already selected — asking `CohortDirectory::isJoinable(int)` per
    | card would be a query per row on an anonymous page.
    */
    cohortAt($this->course);

    DB::enableQueryLog();
    doorPayload($this->course);
    $withOne = count(DB::getQueryLog());
    DB::disableQueryLog();

    /*
    | ⚠️ THE FIXTURE IS BUILT WITH THE LOG OFF. Creating the extra groups inside
    | the measured window counts three INSERTs as three reads and reports an N+1
    | that is not there — which is what the first draft of this test did.
    */
    cohortAt($this->course);
    cohortAt($this->course);
    cohortAt($this->course);

    /*
    | ⚠️ `flushQueryLog()`, NOT JUST `enableQueryLog()`. Disabling the log does
    | not empty it, so the second window still holds the first window's queries
    | and the count comes back doubled — a per-group N+1 that is not there.
    */
    DB::flushQueryLog();
    DB::enableQueryLog();
    doorPayload($this->course);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($log))->toBe($withOne, 'a query per group: '
        .implode(' | ', array_column($log, 'query')));
});
