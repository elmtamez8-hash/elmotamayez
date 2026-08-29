<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\PlatformSettings;

/*
| The ceiling (T085 · FR-023).
|
| ⚠️ THE CAP IS THE ONLY REAL DEFENCE, and the self-referral check is not a
| substitute for it. Somebody with a hundred throwaway email addresses passes
| every identity equality perfectly; what stops them is a number.
|
| ⚠️ AND REFUSING PAST THE CAP LEAVES THE REFERRAL `pending`, NOT `completed`.
| The distinction is the whole reason the check sits BEFORE the flip: a referral
| left pending can be reviewed and paid by hand, while one flipped `completed`
| with nothing awarded is invisible — and, because the flip is a one-way
| conditional UPDATE, unrepeatable. That is also why the `invite_friend`
| catalogue row carries no `daily_cap`: `AwardPoints` returning null past one
| would produce exactly that state, from the other direction.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->inviter = User::factory()->create();
});

function subscribe(User $invited): void
{
    PaymentApproved::dispatch(Order::create([
        'workspace_id' => test()->workspace->getKey(),
        'user_id' => $invited->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]));
}

function inviteAndSubscribe(): Referral
{
    $invited = User::factory()->create();

    $referral = Referral::create([
        'referrer_user_id' => test()->inviter->getKey(),
        'referred_user_id' => $invited->getKey(),
    ]);

    subscribe($invited);

    return $referral->refresh();
}

afterEach(function (): void {
    PlatformSettings::flush();
});

it('completes referrals up to the cap and refuses the one after it', function (): void {
    PlatformSettings::set('referral.max_completed_per_referrer', 2);

    expect(inviteAndSubscribe()->status)->toBe(ReferralStatus::Completed)
        ->and(inviteAndSubscribe()->status)->toBe(ReferralStatus::Completed);

    // The third. Left PENDING — reviewable and payable by hand — not completed
    // with nothing behind it.
    $third = inviteAndSubscribe();

    expect($third->status)->toBe(ReferralStatus::Pending)
        ->and($third->completed_at)->toBeNull()
        // Two completions, two parties each.
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(4);
});

it('treats a cap of zero as switched off, never as unlimited', function (): void {
    // The failure direction that costs money: a falsy cap read as «no ceiling».
    PlatformSettings::set('referral.max_completed_per_referrer', 0);

    expect(inviteAndSubscribe()->status)->toBe(ReferralStatus::Pending)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(0);
});

it('does not count a reversed referral against the ceiling', function (): void {
    // Nothing was kept, so nothing should be held against them.
    PlatformSettings::set('referral.max_completed_per_referrer', 1);

    $first = inviteAndSubscribe();

    expect($first->status)->toBe(ReferralStatus::Completed);

    $first->forceFill([
        'status' => ReferralStatus::Reversed->value,
        'reversed_at' => now(),
    ])->save();

    expect(inviteAndSubscribe()->status)->toBe(ReferralStatus::Completed);
});

it('counts each inviter separately', function (): void {
    // A cap is per referrer, not platform-wide: one popular teacher hitting it
    // must not stop every other person on the platform inviting anybody.
    PlatformSettings::set('referral.max_completed_per_referrer', 1);

    inviteAndSubscribe();

    $otherInviter = User::factory()->create();
    $invited = User::factory()->create();

    $referral = Referral::create([
        'referrer_user_id' => $otherInviter->getKey(),
        'referred_user_id' => $invited->getKey(),
    ]);

    subscribe($invited);

    expect($referral->refresh()->status)->toBe(ReferralStatus::Completed);
});
