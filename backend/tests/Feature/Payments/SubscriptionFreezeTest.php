<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;

/*
| FR-031 — «the effect of a freeze on a subscription's length must be declared
| AND applied» (T095).
|
| Four moments, and three of them are the ones that get forgotten. The file walks
| all four, and the LIFT is the sharpest: an extension that outlives the reason
| for it is a month of access nobody paid for, granted by a row that no longer
| exists.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
    ]);

    $this->buyer = User::factory()->create(['last_workspace_id' => null]);
    $this->approver = makePlatformStaff(Roles::FINANCE_ADMIN);
});

function activeSubscription(): Subscription
{
    $order = app(PurchaseSubscription::class)->handle(test()->buyer, (string) test()->plan->uuid);
    app(ApproveOrder::class)->handle($order, test()->approver);

    return Subscription::query()->withoutWorkspaceScope()->firstOrFail();
}

function freezeDays(int $days, ?User $student = null, ?CarbonImmutable $from = null): FreezePeriod
{
    $from ??= CarbonImmutable::today()->addDays(5);

    return FreezePeriod::create([
        'workspace_id' => test()->workspace->getKey(),
        'student_user_id' => $student?->getKey(),
        'starts_on' => $from,
        'ends_on' => $from->addDays($days - 1),
        'reason' => 'إجازة',
        'created_by' => test()->owner->getKey(),
    ]);
}

it('extends a running subscription when a freeze is DECLARED', function (): void {
    $subscription = activeSubscription();
    $sold = $subscription->ends_on->toDateString();

    freezeDays(7);

    $subscription->refresh();

    expect($subscription->ends_on->toDateString())->toBe($sold)
        ->and($subscription->effective_ends_on->toDateString())
        ->toBe(CarbonImmutable::parse($sold)->addDays(7)->toDateString());
});

it('takes the extension back when the freeze is LIFTED', function (): void {
    /*
    | ⚠️ THE MOMENT THAT GETS FORGOTTEN. A recompute wired only to create and
    | update leaves this passing green while the student keeps a week nobody is
    | paying for — and no later event will ever correct it, because deleting the
    | period was the last thing that happened to it.
    */
    $subscription = activeSubscription();
    $sold = $subscription->ends_on->toDateString();

    $period = freezeDays(7);

    expect($subscription->refresh()->effective_ends_on->toDateString())->not->toBe($sold);

    $period->delete();

    expect($subscription->refresh()->effective_ends_on->toDateString())->toBe($sold);
});

it('follows an EDIT to the period', function (): void {
    $subscription = activeSubscription();
    $sold = CarbonImmutable::parse($subscription->ends_on->toDateString());

    $period = freezeDays(7);
    $period->forceFill(['ends_on' => CarbonImmutable::parse($period->starts_on)->addDays(13)])->save();

    expect($subscription->refresh()->effective_ends_on->toDateString())
        ->toBe($sold->addDays(14)->toDateString());
});

it('is born with the extension when it starts INSIDE a running freeze', function (): void {
    /*
    | ⚠️ THE FOURTH MOMENT, AND IT CANNOT COME FROM THE FREEZE EVENT. That period
    | was written before this subscription existed, so nothing will fire again —
    | a subscription activated here would be short by the whole overlap, for ever.
    */
    freezeDays(10, from: CarbonImmutable::today()->subDays(2));

    $subscription = activeSubscription();

    expect($subscription->effective_ends_on->greaterThan($subscription->ends_on))->toBeTrue();
});

it('counts a day once when two freezes overlap it', function (): void {
    // ⚠️ A SET OF DAYS, NOT A SUM OF LENGTHS. A workspace-wide holiday and this
    // student's own suspension across the same week would otherwise hand them a
    // fortnight for one week of freeze.
    $subscription = activeSubscription();
    $sold = CarbonImmutable::parse($subscription->ends_on->toDateString());

    $start = CarbonImmutable::today()->addDays(5);

    freezeDays(7, from: $start);
    freezeDays(7, student: $this->buyer, from: $start);

    expect($subscription->refresh()->effective_ends_on->toDateString())
        ->toBe($sold->addDays(7)->toDateString());
});

it('leaves another student\'s subscription alone when the freeze names one person', function (): void {
    $subscription = activeSubscription();
    $sold = $subscription->ends_on->toDateString();

    freezeDays(7, student: User::factory()->create());

    expect($subscription->refresh()->effective_ends_on->toDateString())->toBe($sold);
});

it('does not revive a subscription that has already finished', function (): void {
    /*
    | Extending an ended subscription would restore access somebody was told had
    | stopped, weeks later, with no notification and no order behind it. A freeze
    | declared today is about the time still being sold.
    */
    $subscription = Subscription::factory()->expired()->create([
        'plan_id' => $this->plan->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'student_user_id' => $this->buyer->getKey(),
    ]);

    $before = $subscription->effective_ends_on->toDateString();

    freezeDays(30, from: CarbonImmutable::today()->subDays(20));

    expect($subscription->refresh()->effective_ends_on->toDateString())->toBe($before);
});
