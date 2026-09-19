<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\SubscriptionEligibility;

/*
| ٠٣٦ · T017 — the subscription's course is read from `orders.course_id`.
|
| ⛔ `subscriptions` CARRIES NEITHER `course_id` NOR `cohort_id`. The order is the
| only row in the chain that names what was bought, and `PurchaseSubscription`
| writes that column from `CoveredCourses::coverageCourseId()` — the same class
| the coverage question is asked of everywhere else. Reading it back is what
| keeps a room of thirty subscribers from becoming thirty cohort lookups, one per
| seat, at the charge door.
|
| ⚠️ THE FIRST CASE DELIBERATELY MAKES THE TWO ANSWERS DISAGREE, because that is
| the only shape that measures WHICH of them is read. A fixture where the plan
| and the order name the same course is green against an implementation that
| ignores the order entirely — measured: mutating the order branch to `false` left
| every existing subscription test in this suite passing.
|
| ⚠️ AND THE THIRD CASE IS «EMPTY IS NOT A REFUSAL». Workspace coverage writes no
| course id, and so does every subscription older than the column, so a null has
| to fall through to the plan's own coverage. Read as «no», it would close a
| course the student paid for — silently, for every row that predates the line.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->bought = courseWithRate((int) $this->workspace->getKey());
    $this->bought->forceFill(['status' => 'published'])->save();

    $this->other = courseWithRate((int) $this->workspace->getKey());
    $this->other->forceFill(['status' => 'published'])->save();

    $this->student = User::factory()->create();

    $this->plan = Plan::factory()
        ->forCourse($this->bought->uuid)
        ->create(['workspace_id' => $this->workspace->getKey()]);

    $this->subscription = Subscription::factory()->create([
        'plan_id' => $this->plan->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);
});

it('opens the course the order names, not the one the plan was later repointed at', function (): void {
    // The teacher repoints the plan AFTER the sale. The plan moves; the
    // subscription does not — the order still names what was paid for.
    $this->plan->forceFill(['coverage_uuid' => $this->other->uuid])->save();

    $eligibility = app(SubscriptionEligibility::class);

    expect($eligibility->coversCourse((int) $this->student->getKey(), (int) $this->bought->getKey()))->toBeTrue()
        ->and($eligibility->coversCourse((int) $this->student->getKey(), (int) $this->other->getKey()))->toBeFalse();
});

it('does not open a second course of the same teacher', function (): void {
    $eligibility = app(SubscriptionEligibility::class);

    expect($eligibility->coversCourse((int) $this->student->getKey(), (int) $this->other->getKey()))->toBeFalse();
});

it('falls back to the plan coverage when the order names no course', function (): void {
    // The shape of every subscription written before the column was read back:
    // the order carries nothing, and the plan is the only thing left to ask.
    $this->subscription->order->forceFill(['course_id' => null])->save();

    $eligibility = app(SubscriptionEligibility::class);

    expect($eligibility->coversCourse((int) $this->student->getKey(), (int) $this->bought->getKey()))->toBeTrue();
});

it('loads the order for a reader sitting in another workspace', function (): void {
    /*
     * ⚠️ TWO WORKSPACES, AND AN OFFICER WHO OWNS ONE OF THEM. The subscription
     * query is unscoped and that says nothing about the relation query beneath
     * it: a plain `->with('order')` runs Order's own global scope and returns
     * null for every row outside the reader's workspace. The answer would still
     * be right — the fallback covers it — while every read quietly paid for a
     * directory lookup the eager load exists to save.
     */
    [$foreignWorkspace, $foreignOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($foreignWorkspace, $foreignOwner);

    $live = app(SubscriptionEligibility::class)->liveFor((int) $this->student->getKey());

    expect($live)->toHaveCount(1)
        ->and($live->first()->relationLoaded('order'))->toBeTrue()
        ->and($live->first()->order)->not->toBeNull()
        ->and((int) $live->first()->order->course_id)->toBe((int) $this->bought->getKey());
});
