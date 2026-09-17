<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| ٠٣٦ · T027…T030 — a plan sold by the HOUR carries its own shape, and never a
| window of zero.
|
| ⛔ THE DEFECT THIS FILE MEASURES IS SILENT AND COSTS THE STUDENT EVERYTHING
| THEY PAID. A session plan's `duration_days` is NULL, `(int) null` is 0, and a
| snapshot carrying zero reaches `addDays(0)`: the subscription's end date equals
| its start date, so it has expired the instant it was activated. Nothing throws,
| nothing is logged, and every screen is correct about an empty window.
|
| ⚠️ WITH A POSITIVE CONTROL IN THE SAME FILE, because «no subscription was
| written» is equally true of a fixture where the listener never ran, where the
| order was never approved, or where the plan could not be resolved. A month-long
| plan bought and approved by the same helpers, producing a real thirty-day
| window, is what makes the absence mean something.
|
| ⚠️ AND THE OFFICER OWNS A DIFFERENT WORKSPACE, the one fixture line that
| exposed all five layers of the 024 defect on this very approval path.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $this->otherWorkspace->getKey()])->save();
});

/**
 * A private-hours plan of this teacher, in whichever shape the case needs.
 *
 * Course coverage and an individual room size: `SavePlan` refuses hours on
 * workspace coverage (no course to hang the balance on) and refuses a group plan
 * priced for one-to-one, so this is the one shape both guards allow.
 */
function shapedPlan(bool $bySessions): Plan
{
    $factory = $bySessions ? Plan::factory()->bySessions(12) : Plan::factory();

    return $factory->create([
        'workspace_id' => test()->workspace->getKey(),
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => test()->course->uuid,
    ]);
}

function buyIt(Plan $plan): Order
{
    return app(PurchaseSubscription::class)->handle(test()->student, (string) $plan->uuid);
}

it('writes the hours into the snapshot and no window beside them', function (): void {
    $order = buyIt(shapedPlan(bySessions: true));

    $intent = SubscriptionIntent::fromOrder($order);

    expect($intent)->not->toBeNull()
        ->and($intent->sessionCount)->toBe(12)
        // ⛔ NULL, NEVER ZERO. Zero is a number `addDays()` accepts.
        ->and($intent->durationDays)->toBeNull()
        ->and($intent->isSessionShaped())->toBeTrue();
});

it('does not activate an hours order into a subscription that expired at birth', function (): void {
    $order = buyIt(shapedPlan(bySessions: true));

    app(ApproveOrder::class)->handle($order, $this->officer);

    expect(Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->count())->toBe(0);
});

it('still writes a real window for a plan sold by the month', function (): void {
    // The positive control. Without it the case above is green against a build
    // where approval does nothing at all.
    $order = buyIt(shapedPlan(bySessions: false));

    app(ApproveOrder::class)->handle($order, $this->officer);

    $subscription = Subscription::query()
        ->withoutWorkspaceScope()
        ->where('order_id', $order->getKey())
        ->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->starts_on->toDateString())->not->toBe($subscription->ends_on->toDateString())
        ->and($subscription->starts_on->diffInDays($subscription->ends_on))->toBe(30.0);
});

it('reads a truncated snapshot as no window rather than as a window of zero', function (): void {
    /*
     * The shape a metadata blob takes when it is cut short, and the shape a plan
     * re-sold by the hour leaves behind on an order placed before the change.
     * `addDays(0)` is what must not happen to either.
     */
    $order = buyIt(shapedPlan(bySessions: false));

    $metadata = $order->metadata;
    $metadata['duration_days'] = 0;
    $order->forceFill(['metadata' => $metadata])->save();

    app(ApproveOrder::class)->handle($order->refresh(), $this->officer);

    expect(Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->count())->toBe(0);
});
