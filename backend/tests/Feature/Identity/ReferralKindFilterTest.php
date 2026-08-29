<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Identity\Listeners\CompleteReferral;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Store\Actions\FulfilStorePurchase;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;

/*
| ⚠️ THE CHEAPEST NOTEBOOK IN THE STORE MUST NOT COMPLETE A REFERRAL (T085).
|
| FR-019 says «an actual approved subscription». A listener bound to
| `PaymentApproved` with no `kind` filter pays out for a five-riyal notebook —
| and a teacher approves their own store sales, so that is an open mint: invite
| an account, sell it a notebook, approve the sale yourself, collect the points.
|
| The negative case is worthless without its positive controls beside it: a
| filter that refuses EVERYTHING passes «the store does not complete a referral»
| perfectly. So all three kinds are walked, through the real purchase paths.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->inviter = User::factory()->create();
});

function pendingReferralFor(User $invited): Referral
{
    return Referral::create([
        'referrer_user_id' => test()->inviter->getKey(),
        'referred_user_id' => $invited->getKey(),
    ]);
}

it('does NOT complete a referral when the invited student buys a store item', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 500,
    ]);

    $invited = User::factory()->create();
    $referral = pendingReferralFor($invited);

    $purchase = app(PurchaseStoreItem::class)->handle($invited, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
    ]));

    $order = Order::query()->whereKey($purchase->order_id)->firstOrFail();

    // The real approval path, listeners and all.
    app(FulfilStorePurchase::class)->handle($order);
    PaymentApproved::dispatch($order);

    expect($referral->refresh()->status)->toBe(ReferralStatus::Pending)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(0);
});

it('completes a referral when the invited student buys credits', function (): void {
    /*
    | The credits half, through `PurchaseCredits` rather than a hand-built order:
    | an approved credits order with no `credit_purchase` row behind it makes
    | `RecordCreditPurchase` throw, so the fixture has to be real — and a real one
    | proves the wiring as well as the filter.
    */
    $course = courseWithRate((int) $this->workspace->getKey(), 5_000);
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);
    PlatformSettings::set('billing.gateway_fee_bps', 0);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', 0);

    $package = CreditPackage::query()->create([
        'name' => 'أربع حصص',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $referral = pendingReferralFor($student);

    $purchase = app(PurchaseCredits::class)->handle($student, $course, $package);
    $order = Order::query()->whereKey($purchase->order_id)->firstOrFail();

    $order->forceFill(['status' => 'approved'])->save();
    PaymentApproved::dispatch($order);

    expect($referral->refresh()->status)->toBe(ReferralStatus::Completed)
        ->and(AwardEntry::query()->where('action_key', 'invite_friend')->count())->toBe(2);

    PlatformSettings::flush();
});

it('completes a referral on a subscription order', function (): void {
    $invited = User::factory()->create();
    $referral = pendingReferralFor($invited);

    PaymentApproved::dispatch(Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $invited->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]));

    expect($referral->refresh()->status)->toBe(ReferralStatus::Completed);
});

it('completes through a gateway capture as well as a manual approval', function (): void {
    /*
    | ⚠️ TWO DOORS ON ONE PAYMENT, and binding only one leaves every referral
    | completed through the other silently pending for ever. The credits mint is
    | bound to both for this reason; nothing but a test says so for this listener.
    */
    $invited = User::factory()->create();
    $referral = pendingReferralFor($invited);

    $order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $invited->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'stripe',
        'status' => 'approved',
    ]);

    app(CompleteReferral::class)->handle(
        new PaymentCaptured(
            $order,
            PaymentTransaction::create([
                'workspace_id' => $this->workspace->getKey(),
                'order_id' => $order->getKey(),
                'provider' => 'stripe',
                'amount_minor' => 10_000,
                'currency' => 'QAR',
                'status' => PaymentStatus::Captured,
                'reference' => 'REF-CAPTURE-REFERRAL',
            ]),
        ),
    );

    expect($referral->refresh()->status)->toBe(ReferralStatus::Completed);
});
