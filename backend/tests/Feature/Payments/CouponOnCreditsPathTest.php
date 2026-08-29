<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\RecordCreditPurchase;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\CouponRedemption;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| The THIRD purchase path (T067 · T072).
|
| ⚠️ `CouponLeakTest` CLAIMS TO WALK «EVERY ACTION THAT READS A COUPON» AND WALKED
| THREE OF FOUR — `PurchaseCredits` was absent, which is the guard-described-but-
| not-written shape this repository has recorded more than once. This file is the
| fourth walk, and it also carries the credits-side twin of
| `CouponNeverTouchesTeacherTest`, which was asserted nowhere.
|
| ⚠️ THE SNAPSHOT AND THE ORDER ARE ALLOWED TO DIFFER HERE, DELIBERATELY.
| `credit_purchases` records what the four parts of the price WERE at the moment
| of sale — the teacher's approved rate among them — and the settlement close
| reads it to decide what the teacher is owed. Discounting it would take a
| platform campaign out of a teacher's pay, which is the one thing FR-010 forbids
| by name. So the ORDER is what the buyer transfers and the SNAPSHOT is what the
| sale was worth; the difference is the platform's, and it is on the redemption
| row.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    [$this->theirs, $this->stranger] = $this->createWorkspaceWithOwner();

    $this->course = courseWithRate((int) $this->workspace->getKey(), 5_000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);
    PlatformSettings::set('billing.gateway_fee_bps', 0);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', 0);

    $this->package = CreditPackage::query()->create([
        'name' => 'أربع حصص',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    // (5000 + 500) × 4 = 22 000, no gateway configured.
    $this->total = 22_000;
});

afterEach(function (): void {
    PlatformSettings::flush();
});

function buyCredits(?string $code): array
{
    Sanctum::actingAs(test()->student);

    return test()->postJson('/api/v1/billing/purchases', array_filter([
        'course' => test()->course->uuid,
        'package' => test()->package->uuid,
        'coupon_code' => $code,
    ], fn ($value): bool => $value !== null))->json();
}

it('discounts the order and leaves the price snapshot whole', function (): void {
    $coupon = Coupon::factory()->percent(25)->create();

    buyCredits($coupon->code);

    $purchase = CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail();
    $order = Order::query()->whereKey($purchase->order_id)->firstOrFail();

    expect((int) $order->amount_minor)->toBe(16_500)
        // The two numbers differ on purpose — see the header.
        ->and((int) $purchase->total_minor)->toBe($this->total);
});

it('writes the same teacher rate with a coupon as without one', function (): void {
    /*
    | ⚠️ THE COLUMN THE SETTLEMENT CLOSE READS. A discount reaching it is a
    | platform campaign paid for out of a teacher's salary, which FR-010 forbids
    | by name — and one assertion on a single purchase cannot see a formula that
    | discounts BOTH sides, so the two runs are compared against each other.
    |
    | (It is stored per LINE, rate × credits, not per unit. A literal here would
    | have read as the per-unit rate and been wrong for a reason having nothing
    | to do with coupons.)
    */
    buyCredits(null);
    $without = (int) CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail()->teacher_rate_minor;

    CreditPurchase::query()->withoutWorkspaceScope()->delete();
    Order::query()->withoutWorkspaceScope()->delete();

    $coupon = Coupon::factory()->percent(25)->create();
    buyCredits($coupon->code);

    $purchase = CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail();

    expect((int) $purchase->teacher_rate_minor)->toBe($without)
        // And the buyer really did pay less, or the line above is satisfied by a
        // coupon that did nothing at all.
        ->and((int) Order::query()->whereKey($purchase->order_id)->value('amount_minor'))
        ->toBeLessThan($this->total);
});

it('records the redemption against the credits order', function (): void {
    $coupon = Coupon::factory()->percent(25)->create();

    buyCredits($coupon->code);

    $purchase = CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail();
    $redemption = CouponRedemption::query()->firstOrFail();

    expect((int) $redemption->order_id)->toBe((int) $purchase->order_id)
        ->and((int) $redemption->discount_minor)->toBe(5_500)
        ->and((int) $coupon->refresh()->redemptions_count)->toBe(1);
});

it('still mints every credit that was bought', function (): void {
    /*
    | ⚠️ THE CREDITS ARE WHAT THE PACKAGE SAYS, NOT WHAT THE MONEY DIVIDES INTO.
    | A discount reduces what is PAID and not what is RECEIVED — the student
    | bought four sessions and gets four. `RecordCreditPurchase::handle()` mints
    | `$purchase->credits` flat, and the pro-rating one method below it belongs
    | to the SURPLUS path, where there is no order left to close.
    */
    $coupon = Coupon::factory()->percent(25)->create();

    buyCredits($coupon->code);

    $purchase = CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail();
    $order = Order::query()->whereKey($purchase->order_id)->firstOrFail();

    $entry = app(RecordCreditPurchase::class)->handle($order);

    expect($entry)->not->toBeNull()
        ->and((int) $entry->credits)->toBe(4);
});

it('refuses a coupon belonging to another teacher on this path too', function (): void {
    $foreign = Coupon::factory()->percent(50)->forWorkspace((int) $this->theirs->getKey())->create();

    buyCredits($foreign->code);

    expect(CreditPurchase::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(CouponRedemption::query()->count())->toBe(0);
});

it('spends a coupon scoped to this very package', function (): void {
    // The one scope kind no other test spends, and the positive control for the
    // refusal above.
    $coupon = Coupon::factory()
        ->percent(10)
        ->scopedTo(CouponScope::CreditPackage, (string) $this->package->uuid)
        ->create();

    buyCredits($coupon->code);

    $purchase = CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail();

    expect((int) Order::query()->whereKey($purchase->order_id)->value('amount_minor'))
        ->toBe($this->total - 2_200);
});

it('buys at full price with no code, which is the control', function (): void {
    buyCredits(null);

    $purchase = CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail();

    expect((int) Order::query()->whereKey($purchase->order_id)->value('amount_minor'))
        ->toBe($this->total)
        ->and(CouponRedemption::query()->count())->toBe(0);
});
