<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentReversed;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

/*
| ⛔ OWNER DECISION 2026-09-25 — ONLY THE ORDER THAT COMPLETED THE REFERRAL.
|
| The listener used to key on the invited PERSON, so any reversed payment of
| theirs — an unrelated course order months later — took the inviter's points
| back for a subscription that is still paid and still running.
*/
function referralOrder(OrderKind $kind): Order
{
    return Order::create([
        'workspace_id' => test()->workspace->getKey(),
        'user_id' => test()->invited->getKey(),
        'kind' => $kind,
        'amount_minor' => 5_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);
}

function reverseReferralOrder(Order $order): void
{
    PaymentReversed::dispatch(
        $order,
        PaymentTransaction::create([
            'workspace_id' => test()->workspace->getKey(),
            'order_id' => $order->getKey(),
            'provider' => 'manual',
            'amount_minor' => 5_000,
            'currency' => 'QAR',
            'status' => PaymentStatus::Reversed,
            'method' => PaymentMethod::BankTransfer,
            'reference' => 'REF-UNRELATED-'.uniqid(),
        ]),
        'استرداد طلب آخر.',
    );
}

it('records the order that completed the referral', function (): void {
    PaymentApproved::dispatch($this->order);

    expect($this->referral->refresh()->completing_order_id)->toBe((int) $this->order->getKey());
});

it('keeps the points when an unrelated course order of the same student is reversed', function (): void {
    PaymentApproved::dispatch($this->order);

    $inviterAfterAward = xpFor($this->inviter);

    reverseReferralOrder(referralOrder(OrderKind::Course));

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Completed)
        ->and(xpFor($this->inviter))->toBe($inviterAfterAward);

    // And the completing order still reverses it afterwards.
    reverseThePayment();

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Reversed);
});

it('keeps the points when a later subscription, not the completing one, is reversed', function (): void {
    PaymentApproved::dispatch($this->order);

    $renewal = referralOrder(OrderKind::Subscription);
    PaymentApproved::dispatch($renewal);

    reverseReferralOrder($renewal);

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Completed);
});

it('reverses a legacy referral with no recorded order on no order at all', function (): void {
    PaymentApproved::dispatch($this->order);

    // A row completed before the column existed, which the backfill could not
    // attribute without guessing.
    $this->referral->forceFill(['completing_order_id' => null])->save();

    reverseThePayment();

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Completed);
});

it('backfills the completing order only when exactly one paid order could be it', function (): void {
    PaymentApproved::dispatch($this->order);
    $this->referral->forceFill(['completing_order_id' => null])->save();

    $backfill = fn () => (require base_path('app/Modules/Identity/Database/Migrations/2026_09_25_000900_add_completing_order_id_to_referrals.php'))->up();

    $backfill();

    expect($this->referral->refresh()->completing_order_id)->toBe((int) $this->order->getKey());

    // A second paid qualifying order placed before completion makes it a guess.
    $this->referral->forceFill(['completing_order_id' => null])->save();
    referralOrder(OrderKind::Credits)->forceFill(['created_at' => now()->subDay()])->save();

    $backfill();

    expect($this->referral->refresh()->completing_order_id)->toBeNull();
});

it('reverses on a credit refund only from the balance the completing order filled', function (): void {
    $creditsOrder = referralOrder(OrderKind::Credits);

    $paidFor = billingBalance($this->workspace, $this->invited, courseWithRate((int) $this->workspace->getKey()));
    $other = billingBalance($this->workspace, $this->invited, courseWithRate((int) $this->workspace->getKey()));

    DB::table('credit_purchases')->insert([
        'uuid' => (string) Str::uuid(),
        'credit_balance_id' => $paidFor->getKey(),
        'credit_package_id' => 1,
        'course_id' => $paidFor->course_id,
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $creditsOrder->getKey(),
        'credits' => 4,
        'teacher_rate_minor' => 1_000,
        'operating_fee_minor' => 100,
        'gateway_fee_minor' => 100,
        'total_minor' => 4_800,
        'currency' => 'QAR',
        'purchased_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    grantCredits($paidFor, 4, 'paid-for');
    grantCredits($other, 4, 'other-course');

    PaymentApproved::dispatch($creditsOrder);

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Completed);

    $refund = fn (CreditBalance $balance, string $key) => app(AdjustCredits::class)->handle(
        $balance,
        CreditTransactionType::Refund,
        -1,
        'استرداد بطلب الطالب',
        $key,
    );

    // Another course's credits coming back is not the completing payment.
    $refund($other, 'refund-other');

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Completed);

    $refund($paidFor, 'refund-paid-for');

    expect($this->referral->refresh()->status)->toBe(ReferralStatus::Reversed);
});
