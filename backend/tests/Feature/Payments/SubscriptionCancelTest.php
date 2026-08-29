<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\CancelSubscription;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| Undoing a purchase — and the production caller `PaymentReversed` never had.
|
| ⚠️ `ReversePayment` HAS FIRED `PaymentReversed` SINCE 006 AND NO FILE IN THE
| TREE EVER CALLED IT. Not a route, not a screen, not another Action. So every
| listener bound to that event — `ReevaluateOnReversal` since 006, and
| `ReverseReferralAward` since this spec's own US3 — was correctly wired to a
| door with nothing on the other side, and SC-007 was «proved» by tests
| dispatching the event by hand. This file walks the chain end to end with ZERO
| hand-fired events, which is the only shape that can tell the two apart.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
        'price_minor' => 30_000,
    ]);

    $this->buyer = User::factory()->create(['last_workspace_id' => null]);
    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
});

function buyAndApprove(): Subscription
{
    $order = app(PurchaseSubscription::class)->handle(test()->buyer, (string) test()->plan->uuid);
    app(ApproveOrder::class)->handle($order, test()->officer);

    return Subscription::query()->withoutWorkspaceScope()->firstOrFail();
}

it('stops the subscription, shuts the access, and reverses the payment', function (): void {
    $subscription = buyAndApprove();

    app(CancelSubscription::class)->handle($subscription, 'طلب الطالب');

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->cancelled_at)->not->toBeNull();

    expect(Enrollment::query()
        ->withoutWorkspaceScope()
        ->where('order_id', $subscription->order_id)
        ->value('status'))->toBe('expired');

    $transaction = PaymentTransaction::query()
        ->withoutWorkspaceScope()
        ->where('order_id', $subscription->order_id)
        ->firstOrFail();

    expect($transaction->status)->toBe(PaymentStatus::Reversed)
        // ⚠️ The line that lets the student pay again. Without it the unique index
        // still holds their old capture's claim and that order can never be paid
        // for a second time — silently, and permanently.
        ->and($transaction->captured_order_id)->toBeNull();
});

it('takes back the referral points, through the real chain and not a hand-fired event', function (): void {
    /*
    | ⚠️ THE POINT OF THE WHOLE FILE. FR-021 («cancelling or refunding a referred
    | subscription must reverse the reward») was implemented, tested and
    | unreachable: the listener is bound to `PaymentReversed` and nothing in the
    | product called `ReversePayment`.
    |
    | Every event below is fired by production code. Nothing here dispatches one.
    */
    $inviter = User::factory()->create(['last_workspace_id' => null]);

    $referral = Referral::create([
        'referrer_user_id' => $inviter->getKey(),
        'referred_user_id' => $this->buyer->getKey(),
    ]);

    $subscription = buyAndApprove();

    // The approval completed it — the positive control, without which the
    // reversal below is a claim about an empty table.
    expect($referral->refresh()->status)->toBe(ReferralStatus::Completed)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(2);

    app(CancelSubscription::class)->handle($subscription, 'استرداد');

    expect($referral->refresh()->status)->toBe(ReferralStatus::Reversed);

    // Asserted on the AGGREGATE, never on a row count: counting entries passes
    // just as well against a design that returns nothing.
    expect((int) AwardEntry::query()->where('action_key', 'invite_friend')->sum('xp'))->toBe(0);
});

it('leaves an older course\'s dues exactly where they were', function (): void {
    /*
    | FR-029 — «cancelling must not wipe an existing prior course's dues». Held by
    | NOT WRITING to them rather than by remembering not to: the Action's two
    | statements name this subscription and this order and nothing else.
    */
    $olderCourse = courseWithRate((int) $this->workspace->getKey());
    $balance = billingBalance($this->workspace, $this->buyer, $olderCourse);

    consumeCredits($balance, 3, 'older_dues');

    $before = (int) $balance->refresh()->remaining_credits;

    app(CancelSubscription::class)->handle(buyAndApprove(), 'استرداد');

    expect((int) $balance->refresh()->remaining_credits)->toBe($before)
        ->and($before)->toBe(-3);
});

it('refuses to cancel the same subscription twice', function (): void {
    // Two taps on one button would both read `active`, both proceed, and both
    // call `ReversePayment` — the second throwing from inside a worker over a
    // payment the first already reversed.
    $subscription = buyAndApprove();

    app(CancelSubscription::class)->handle($subscription, 'استرداد');

    expect(fn () => app(CancelSubscription::class)->handle($subscription->refresh(), 'مرّة ثانية'))
        ->toThrow(DomainException::class);
});

it('is refused to the teacher and to the student, and allowed to the platform', function (): void {
    // Money leaving the platform is not a decision either party to the lesson
    // takes alone — the same reason `BILLING_PURCHASE_APPROVE` guards the
    // approval this undoes.
    $subscription = buyAndApprove();

    Sanctum::actingAs($this->buyer);
    $this->postJson("/api/v1/admin/subscriptions/{$subscription->uuid}/cancel", ['reason' => 'غيّرت رأيي'])
        ->assertForbidden();

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/admin/subscriptions/{$subscription->uuid}/cancel", ['reason' => 'قرار المدرّس'])
        ->assertForbidden();

    Sanctum::actingAs($this->officer);
    $this->postJson("/api/v1/admin/subscriptions/{$subscription->uuid}/cancel", ['reason' => 'استرداد'])
        ->assertOk();
});
