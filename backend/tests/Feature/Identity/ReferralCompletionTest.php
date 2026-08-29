<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Models\ReferralCode;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Models\Order;

/*
| SC-006 — nothing is paid before a real approved subscription (T085).
|
| ⚠️ NO `Queue::fake()` ANYWHERE IN THIS FILE. `CompleteReferral` is queued and so
| is the Gamification listener behind it, so a bare fake swallows the entire chain
| and «the referral completed and both parties were paid» becomes a confident
| claim about two empty tables. This repository has recorded that exact vacuum
| three times — the charge listener, the accrual listener, and the notification
| defaults.
|
| ⚠️ AND THE CATALOGUE ROW IS WHAT MAKES THE PAYMENT REAL. `AwardPoints` returns
| in silence for an unknown key, so without `invite_friend` seeded every
| assertion below would pass by comparing zero with zero. `tests/Pest.php` seeds
| it; `ReferralCatalogueTest` is what proves a live database gets it too.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    // Neither party is a workspace member and neither has a seeded context: a
    // student belongs to no workspace in production, and a fixture that stamps
    // `last_workspace_id` measures a person who does not exist.
    $this->inviter = User::factory()->create();
    $this->invited = User::factory()->create();

    ReferralCode::factory()->create(['user_id' => $this->inviter->getKey(), 'code' => 'FRIEND01']);
});

/**
 * ⚠️ `Subscription` AND NOT `Credits`, AND THE REASON IS A FIXTURE ONE.
 * `RecordCreditPurchase` THROWS for an approved credits order with no
 * `credit_purchase` row behind it — correctly, since a mint with no price
 * snapshot would put an unpriced entry in the ledger for ever — so a hand-built
 * credits order cannot be dispatched here without building the whole purchase
 * beside it. `Subscription` is also the word FR-019 uses. The credits half of
 * the `kind` filter is proved in `ReferralKindFilterTest`, through the real
 * `PurchaseCredits` path.
 */
function attachAndPay(User $invited, OrderKind $kind = OrderKind::Subscription): Referral
{
    $referral = Referral::create([
        'referrer_user_id' => test()->inviter->getKey(),
        'referred_user_id' => $invited->getKey(),
    ]);

    $order = Order::create([
        'workspace_id' => test()->workspace->getKey(),
        'user_id' => $invited->getKey(),
        'kind' => $kind,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);

    PaymentApproved::dispatch($order);

    return $referral->refresh();
}

function xpOf(User $user): int
{
    return (int) (StudentProgress::query()->where('user_id', $user->getKey())->value('xp') ?? 0);
}

it('pays nothing at all until a subscription is approved', function (): void {
    $referral = Referral::create([
        'referrer_user_id' => $this->inviter->getKey(),
        'referred_user_id' => $this->invited->getKey(),
    ]);

    expect($referral->status)->toBe(ReferralStatus::Pending)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(0)
        ->and(xpOf($this->inviter))->toBe(0);
});

it('completes the referral and pays BOTH parties on an approved subscription order', function (): void {
    $referral = attachAndPay($this->invited);

    expect($referral->status)->toBe(ReferralStatus::Completed)
        ->and($referral->completed_at)->not->toBeNull();

    // «للطرفَين» — two entries, one source, differing only in the student.
    $entries = AwardEntry::query()->where('action_key', 'invite_friend')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('student_user_id')->sort()->values()->all())
        ->toBe(collect([$this->inviter->getKey(), $this->invited->getKey()])->sort()->values()->all());

    // ⚠️ THE AGGREGATE, NOT THE ROW COUNT. Two entries would also be true of a
    // design that writes them and moves nothing.
    expect(xpOf($this->inviter))->toBeGreaterThan(0)
        ->and(xpOf($this->invited))->toBeGreaterThan(0);
});

it('is idempotent under a redelivered payment', function (): void {
    $referral = attachAndPay($this->invited);

    $order = Order::query()->where('user_id', $this->invited->getKey())->firstOrFail();
    $before = xpOf($this->inviter);

    PaymentApproved::dispatch($order);

    expect(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(2)
        ->and(xpOf($this->inviter))->toBe($before)
        ->and($referral->refresh()->status)->toBe(ReferralStatus::Completed);
});

it('does not re-complete on the invited student second purchase', function (): void {
    attachAndPay($this->invited);

    $before = xpOf($this->inviter);

    // A renewal, months later. The conditional flip finds nothing `pending`.
    $second = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->invited->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);

    PaymentApproved::dispatch($second);

    expect(xpOf($this->inviter))->toBe($before)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(2);
});

it('does nothing for a buyer nobody invited', function (): void {
    $stranger = User::factory()->create();

    $order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $stranger->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);

    PaymentApproved::dispatch($order);

    expect(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(0);
});

it('never completes a flagged referral', function (): void {
    // FR-022: marked for review, paying nothing — and a subscription does not
    // quietly launder it.
    $referral = Referral::factory()->flagged()->create([
        'referrer_user_id' => $this->inviter->getKey(),
        'referred_user_id' => $this->invited->getKey(),
    ]);

    $order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->invited->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);

    PaymentApproved::dispatch($order);

    expect($referral->refresh()->status)->toBe(ReferralStatus::Flagged)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(0);
});
