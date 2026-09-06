<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Testing\TestResponse;

/*
| Spec 027 · US1 — the door a new buyer walks through, and the snapshot it writes.
|
| ⚠️ THIS FILE IS THE FIRST TEST OF `POST /billing/subscriptions` IN THE
| REPOSITORY. Measured before it was written: every existing subscription test
| calls `PurchaseSubscription` directly, so the route's validation, its 422
| shape and its 201 body had never been exercised at all.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->groupPlan = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'الشهري — جماعي',
        'duration_days' => 30,
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $this->privatePlan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'الشهري — فردي',
        'duration_days' => 30,
        'price_minor' => 90_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => 'مجموعة السبت',
        'created_by' => $this->owner->getKey(),
    ]);

    /*
    | ⚠️ `last_workspace_id` IS LEFT NULL AND THE CONTEXT IS RESET. That column is
    | what `WorkspaceContext::id()` falls back to, and NOTHING on a student's path
    | writes it in production. A fixture that stamps it hands the test student a
    | context real students never have, and every scope assertion below would be
    | measuring a person who does not exist.
    */
    $this->buyer = User::factory()->create(['last_workspace_id' => null]);
    app()->forgetInstance(WorkspaceContext::class);
});

/*
 * ⚠️ NAMED FOR THIS FILE, BECAUSE PEST FILES SHARE ONE GLOBAL FUNCTION
 * NAMESPACE. `subscribeAs` was already taken by `PushSharedDeviceTest` with a
 * different signature — two files defining it is a fatal redeclaration the
 * moment they run in one process, and a single-file run passes over it.
 */
function postSubscriptionOrder(User $buyer, array $body): TestResponse
{
    return test()->actingAs($buyer, 'sanctum')->postJson('/api/v1/billing/subscriptions', $body);
}

it('writes the eight-key snapshot from what the server resolved, not from the body', function (): void {
    $response = postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->groupPlan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) $this->cohort->uuid,
    ]);

    $response->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();
    $intent = SubscriptionIntent::fromOrder($order);

    expect($order->kind)->toBe(OrderKind::Subscription)
        ->and($order->status)->toBe('pending')
        ->and((int) $order->amount_minor)->toBe(45_000)
        ->and($intent)->not->toBeNull()
        ->and($intent->planTitle)->toBe('الشهري — جماعي')
        ->and($intent->durationDays)->toBe(30)
        ->and($intent->sessionType)->toBe('group')
        ->and($intent->mode)->toBe('cohort')
        ->and($intent->cohortUuid)->toBe((string) $this->cohort->uuid)
        ->and($intent->cohortName)->toBe('مجموعة السبت')
        ->and($intent->teacherUuid)->toBe((string) $this->owner->uuid)
        ->and($intent->teacherName)->toBe($this->owner->name);
});

it('keeps the group name readable after the group is archived (FR-013)', function (): void {
    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->groupPlan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) $this->cohort->uuid,
    ])->assertCreated();

    $this->cohort->forceFill(['status' => Cohort::ARCHIVED, 'archived_at' => now()])->save();

    $order = Order::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    // The name is a SNAPSHOT, so the officer's row still says which group this
    // was — an id that no longer resolves would be a blank cell on a decision.
    expect(SubscriptionIntent::fromOrder($order)?->targetLabel())->toBe('مجموعة السبت');
});

it('reads «حصص خاصّة» in words for a private subscription, never a blank', function (): void {
    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->privatePlan->uuid,
        'mode' => 'private',
    ])->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    expect(SubscriptionIntent::fromOrder($order)?->targetLabel())->toBe('حصص خاصّة');
});

it('refuses an intent that does not match what the plan sells (FR-008)', function (): void {
    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->groupPlan->uuid,
        'mode' => 'private',
    ])->assertStatus(422);

    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->privatePlan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) $this->cohort->uuid,
    ])->assertStatus(422);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a group belonging to another course of the same teacher', function (): void {
    $otherCourse = courseWithRate((int) $this->workspace->getKey());
    $otherCourse->forceFill(['status' => 'published'])->save();

    $foreign = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $otherCourse->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->groupPlan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) $foreign->uuid,
    ])->assertStatus(422);
});

it('refuses a group belonging to another teacher entirely', function (): void {
    /*
    | ⚠️ THE SECOND WORKSPACE IS THE WHOLE POINT. `Cohort` carries
    | `BelongsToWorkspace`, which protects NOTHING here: the buyer is a member of
    | no workspace, so the context is null and `WorkspaceScope` adds no condition
    | at all. A one-workspace fixture cannot see this, and the uuid arrives in the
    | request body from a student's own browser.
    */
    [$otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner();
    $otherCourse = courseWithRate((int) $otherWorkspace->getKey());
    $otherCourse->forceFill(['status' => 'published'])->save();

    $foreign = Cohort::factory()->create([
        'workspace_id' => $otherWorkspace->getKey(),
        'course_id' => $otherCourse->getKey(),
        'created_by' => $otherOwner->getKey(),
    ]);

    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->groupPlan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) $foreign->uuid,
    ])->assertStatus(422);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a private one-to-one cohort even when a teacher has opened it', function (): void {
    /*
    | Individual cohorts are born `closed` with `capacity: 1` and no membership
    | row, and nothing stops a teacher setting one to `open` from the panel. The
    | uuid is not published anywhere — but this route takes it from the body, so
    | the filter is the guard.
    */
    $individual = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'individual_for_user_id' => User::factory()->create()->getKey(),
        'capacity' => 1,
        'members_count' => 0,
        'status' => Cohort::OPEN,
        'created_by' => $this->owner->getKey(),
    ]);

    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->groupPlan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) $individual->uuid,
    ])->assertStatus(422);
});

it('refuses a second pending order with the same teacher and points at the first (FR-011)', function (): void {
    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->groupPlan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) $this->cohort->uuid,
    ])->assertCreated();

    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->privatePlan->uuid,
        'mode' => 'private',
    ])->assertStatus(422);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('accepts a renewal on the buyer’s own group even when that group is full (FR-028)', function (): void {
    /*
    | ⚠️ THE GROUP IS FULL OF THE BUYER AND THEIR CLASSMATES, WHICH IS THE
    | ORDINARY STATE OF A GROUP ON DAY 28. `isJoinable()` is false here, so a
    | joinable-only check would refuse exactly the renewal US4·4 guarantees —
    | and there is no group for them to «join»: the membership is already open.
    */
    $this->cohort->forceFill(['capacity' => 1, 'members_count' => 1])->save();

    CohortMembership::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->cohort->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->buyer->getKey(),
        'joined_at' => now(),
    ]);

    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->groupPlan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) $this->cohort->uuid,
    ])->assertCreated();
});

it('refuses buying into a DIFFERENT group of a course the buyer is already in', function (): void {
    /*
    | ⚠️ WITHOUT THIS, SUBSCRIBING IS AN UNAPPROVED TRANSFER.
    | `CohortMembershipWriter` closes an open membership elsewhere in the course
    | and opens the new one — no `RequestTransfer`, no teacher decision, and the
    | audit row reads «joined». A student refused a transfer would simply buy the
    | cheapest plan naming the group they wanted.
    */
    CohortMembership::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->cohort->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->buyer->getKey(),
        'joined_at' => now(),
    ]);

    $other = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => 'مجموعة الأحد',
        'created_by' => $this->owner->getKey(),
    ]);

    $response = postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->groupPlan->uuid,
        'mode' => 'cohort',
        'cohort_uuid' => (string) $other->uuid,
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('طلب نقل');
});

it('refuses a cohort uuid on a private purchase rather than ignoring it', function (): void {
    // Accepted-and-ignored is worse than refused: the buyer chose a group and
    // would be sold something else without being told.
    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->privatePlan->uuid,
        'mode' => 'private',
        'cohort_uuid' => (string) $this->cohort->uuid,
    ])->assertStatus(422);
});

it('requires a mode at all', function (): void {
    postSubscriptionOrder($this->buyer, ['plan_uuid' => (string) $this->groupPlan->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mode']);
});

it('names the teacher from the workspace owner, read once and frozen', function (): void {
    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->privatePlan->uuid,
        'mode' => 'private',
    ])->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    expect(SubscriptionIntent::fromOrder($order)?->teacherName)
        ->toBe(Workspace::query()->withoutGlobalScopes()->find($this->workspace->getKey())?->owner?->name);
});

it('is approved at the amount captured when it was ordered, not the new price', function (): void {
    /*
    | Spec 027 · US4·٣ — a manual transfer takes days, and a teacher may
    | legitimately reprice inside that lag. Reading the plan at approval would
    | charge the student a number they were never shown.
    |
    | The guard is `amount_minor` on the order and it is not new; what is new is
    | that it is now MEASURED, because 027 is what puts days between the order and
    | the decision as a matter of course.
    */
    postSubscriptionOrder($this->buyer, [
        'plan_uuid' => (string) $this->privatePlan->uuid,
        'mode' => 'private',
    ])->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    $this->privatePlan->forceFill(['price_minor' => 500_000, 'title' => 'اسم آخر'])->save();

    expect((int) $order->refresh()->amount_minor)->toBe(90_000)
        // And the snapshot keeps the NAME the buyer read, too (FR-014).
        ->and(SubscriptionIntent::fromOrder($order)?->planTitle)->toBe('الشهري — فردي');
});
