<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Identity\Actions\AttachReferral;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Models\ReferralCode;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Models\Order;

/*
| SC-006's second half — zero reward on a self-referral (T085 · FR-022).
|
| ⚠️ IT IS `flagged`, NOT DISCARDED. «The suspicious pattern is marked for
| review, with no payout» — a row silently dropped tells nobody anything and
| cannot be reviewed, and the operator looking for abuse would find an empty
| table and conclude there was none.
|
| ⚠️ AND THE DETECTION IS AN IDENTITY EQUALITY AND NOTHING CLEVERER, deliberately.
| An email-similarity or shared-IP heuristic flags real families on one home
| connection — the population this product is built for — while the actual
| defence against somebody holding a hundred throwaway addresses is the CAP,
| which no equality check could ever provide. See `ReferralCapTest`.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->person = User::factory()->create();

    ReferralCode::factory()->create([
        'user_id' => $this->person->getKey(),
        'code' => 'MYOWN123',
    ]);
});

it('flags a referral where the inviter and the invited are one person', function (): void {
    $referral = app(AttachReferral::class)->handle($this->person, 'MYOWN123');

    expect($referral)->not->toBeNull()
        ->and($referral->status)->toBe(ReferralStatus::Flagged)
        ->and($referral->flagged_reason)->not->toBeNull();
});

it('pays nothing for it however much the person then subscribes', function (): void {
    app(AttachReferral::class)->handle($this->person, 'MYOWN123');

    PaymentApproved::dispatch(Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->person->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]));

    expect(Referral::query()->firstOrFail()->status)->toBe(ReferralStatus::Flagged)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(0);
});

it('leaves a genuine invitation pending, which is the control', function (): void {
    // Without this, a `flagged` branch that flagged EVERYTHING would pass every
    // assertion above.
    $friend = User::factory()->create();

    $referral = app(AttachReferral::class)->handle($friend, 'MYOWN123');

    expect($referral->status)->toBe(ReferralStatus::Pending);
});

it('never fails a registration over a code, whatever the code is', function (): void {
    /*
    | ⚠️ THE MOST IMPORTANT CASE IN THIS FILE. A typo off a poster must not stop
    | a real person creating an account — that is the most hostile thing this
    | feature could do — so an unknown code attaches nothing and says nothing.
    | Nothing is owed until somebody subscribes, so attaching nothing costs
    | nothing.
    */
    $newcomer = User::factory()->create();

    expect(app(AttachReferral::class)->handle($newcomer, 'NOSUCHCODE'))->toBeNull()
        ->and(app(AttachReferral::class)->handle($newcomer, ''))->toBeNull()
        ->and(app(AttachReferral::class)->handle($newcomer, null))->toBeNull()
        ->and(Referral::query()->count())->toBe(0);
});

it('attaches one referral per person, for life', function (): void {
    $friend = User::factory()->create();
    $other = User::factory()->create();

    ReferralCode::factory()->create(['user_id' => $other->getKey(), 'code' => 'SECOND01']);

    app(AttachReferral::class)->handle($friend, 'MYOWN123');

    // A second attach — a re-submitted form, a merged account — is not an error
    // worth failing anything over, and it must not create a second row: two
    // referrals for one person means one subscription completing both.
    expect(app(AttachReferral::class)->handle($friend, 'SECOND01'))->toBeNull()
        ->and(Referral::query()->where('referred_user_id', $friend->getKey())->count())->toBe(1);
});

it('matches a code however it was typed', function (): void {
    $friend = User::factory()->create();

    expect(app(AttachReferral::class)->handle($friend, ' myown123 '))->not->toBeNull();
});
