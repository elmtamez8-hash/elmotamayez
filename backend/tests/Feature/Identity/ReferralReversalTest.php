<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentReversed;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;

/*
| SC-007 — a cancelled subscription reverses the reward, 100% of the time (T086).
|
| ⚠️ EVERY ASSERTION HERE IS ON THE AGGREGATE RETURNING TO ITS PRE-AWARD VALUE,
| NEVER ON A ROW COUNT. Counting two entries passes perfectly against a design
| that writes a compensating row and moves nothing — which is the failure this
| criterion exists to catch, and the reason the same rule is written on
| `AwardReversalTest`.
|
| ⚠️ AND BOTH PARTIES ARE CHECKED. The completion pays two people from one
| source; `data-model.md` proposed a single `award_entry_id` on the referral, and
| a reversal driven through one column would return the inviter's points and
| silently leave the invited student's, for ever. The listener finds both by the
| idempotency triplet instead.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->inviter = User::factory()->create();
    $this->invited = User::factory()->create();

    $this->referral = Referral::create([
        'referrer_user_id' => $this->inviter->getKey(),
        'referred_user_id' => $this->invited->getKey(),
    ]);

    $this->order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->invited->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);
});

function xpFor(User $user): int
{
    return (int) (StudentProgress::query()->where('user_id', $user->getKey())->value('xp') ?? 0);
}

function reverseThePayment(): void
{
    PaymentReversed::dispatch(
        test()->order,
        PaymentTransaction::create([
            'workspace_id' => test()->workspace->getKey(),
            'order_id' => test()->order->getKey(),
            'provider' => 'manual',
            'amount_minor' => 10_000,
            'currency' => 'QAR',
            'status' => PaymentStatus::Reversed,
            'method' => PaymentMethod::BankTransfer,
            'reference' => 'REF-REVERSAL-'.uniqid(),
        ]),
        'ألغى الطالب اشتراكه.',
    );
}

it('returns both parties to exactly the xp they had before the award', function (): void {
    $inviterBefore = xpFor($this->inviter);
    $invitedBefore = xpFor($this->invited);

    PaymentApproved::dispatch($this->order);

    // The award really happened, or every assertion below is satisfied by a
    // feature that never paid anything.
    expect(xpFor($this->inviter))->toBeGreaterThan($inviterBefore)
        ->and(xpFor($this->invited))->toBeGreaterThan($invitedBefore);

    reverseThePayment();

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Reversed)
        ->and($this->referral->reversed_at)->not->toBeNull()
        // ⚠️ THE AGGREGATE. Not the rows.
        ->and(xpFor($this->inviter))->toBe($inviterBefore)
        ->and(xpFor($this->invited))->toBe($invitedBefore);
});

it('writes a compensating entry rather than deleting the original', function (): void {
    PaymentApproved::dispatch($this->order);
    reverseThePayment();

    $originals = AwardEntry::query()
        ->where('action_key', 'invite_friend')
        ->where('reversal_of_id', 0)
        ->count();

    $reversals = AwardEntry::query()
        ->where('action_key', 'invite_friend')
        ->where('reversal_of_id', '<>', 0)
        ->count();

    // The ledger is append-only: what was awarded stays visible, and what took
    // it back sits beside it.
    expect($originals)->toBe(2)->and($reversals)->toBe(2);
});

it('reverses exactly once under a redelivered refund', function (): void {
    PaymentApproved::dispatch($this->order);

    $inviterBefore = xpFor($this->inviter);

    reverseThePayment();

    $afterFirst = xpFor($this->inviter);

    // A queue retry. The conditional `completed → reversed` flip matches zero
    // rows, so nothing is announced and nothing is reversed twice — which would
    // otherwise take the points BELOW where they started.
    reverseThePayment();

    expect(xpFor($this->inviter))->toBe($afterFirst)
        ->and($afterFirst)->toBeLessThan($inviterBefore)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(4);
});

it('does nothing when the referral never completed', function (): void {
    // A refund on a subscription that was never the one to complete the invite.
    // Nothing was paid, so there is nothing to take back — and flipping a pending
    // referral to `reversed` would burn an invitation that has not been used.
    reverseThePayment();

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Pending)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(0);
});

it('does not re-arm the invitation after a reversal', function (): void {
    /*
    | ⚠️ `reversed` IS NOT `pending`. Putting the referral back in the queue would
    | let one person subscribe, be paid, cancel, and subscribe again — minting
    | points on a loop off a single invitation.
    */
    PaymentApproved::dispatch($this->order);
    reverseThePayment();

    $after = xpFor($this->inviter);

    PaymentApproved::dispatch(Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->invited->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]));

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Reversed)
        ->and(xpFor($this->inviter))->toBe($after);
});
