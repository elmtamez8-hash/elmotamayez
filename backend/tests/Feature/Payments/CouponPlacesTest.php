<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\ExpireUnpaidCouponOrders;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\CouponPlaces;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use Illuminate\Http\UploadedFile;

/*
| ⛔ A COUPON'S PLACE FOLLOWS ITS ORDER (owner decision 2026-10-29).
|
| It was taken at purchase and never given back, so throwaway accounts drained a
| limited code with orders nobody paid (QA run, 2026-10-29), and a buyer whose
| receipt was refused lost their place. Now a rejection — an officer's, or the
| 48-hour sweep of an order with no receipt — gives it back, and a receipt
| uploaded onto that rejected order takes one again, or is refused when the
| code ran out meanwhile.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->item = StoreItem::factory()->create(['workspace_id' => $this->workspace->getKey(), 'price_minor' => 10_000]);
    $this->coupon = Coupon::factory()->percent(50)->forWorkspace((int) $this->workspace->getKey())->create(['max_redemptions' => 1]);
});

function placesPurchase(User $buyer, StoreItem $item, string $code): Order
{
    $storeOrder = app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
        'coupon_code' => $code,
    ]));

    return Order::query()->withoutWorkspaceScope()->findOrFail($storeOrder->order_id);
}

function placesReceipt(Order $order, User $buyer): Order
{
    return app(UploadPaymentReceipt::class)->handle($order->refresh(), UploadedFile::fake()->image('receipt.png'), $buyer);
}

it('gives the place back when the order is rejected, so the next buyer can use the code', function (): void {
    $first = User::factory()->create();
    $order = placesPurchase($first, $this->item, $this->coupon->code);

    expect((int) $this->coupon->refresh()->redemptions_count)->toBe(1)
        ->and(fn () => placesPurchase(User::factory()->create(), $this->item, $this->coupon->code))
        ->toThrow(DomainException::class);

    app(RejectOrder::class)->handle(receiptUploaded($order), $this->owner, 'الإيصال غير واضح');

    expect((int) $this->coupon->refresh()->redemptions_count)->toBe(0);

    $second = placesPurchase(User::factory()->create(), $this->item, $this->coupon->code);
    expect($second->exists)->toBeTrue();
});

it('takes the place again for a receipt uploaded onto the rejected order', function (): void {
    $buyer = User::factory()->create();
    $order = placesPurchase($buyer, $this->item, $this->coupon->code);
    app(RejectOrder::class)->handle(receiptUploaded($order), $this->owner, 'الإيصال غير واضح');

    expect(placesReceipt($order, $buyer)->status)->toBe('under_review')
        ->and((int) $this->coupon->refresh()->redemptions_count)->toBe(1);
});

it('refuses that receipt when the code ran out in between, and keeps nothing', function (): void {
    $buyer = User::factory()->create();
    $order = placesPurchase($buyer, $this->item, $this->coupon->code);
    app(RejectOrder::class)->handle(receiptUploaded($order), $this->owner, 'الإيصال غير واضح');

    // Somebody else took the only place.
    placesPurchase(User::factory()->create(), $this->item, $this->coupon->code);

    expect(fn () => placesReceipt($order, $buyer))->toThrow(DomainException::class, CouponPlaces::RECLAIM_REFUSAL);

    $order->refresh();
    expect($order->status)->toBe('rejected')
        ->and($order->getMedia('receipt'))->toHaveCount(0)
        ->and((int) $this->coupon->refresh()->redemptions_count)->toBe(1);
});

it('expires an order with no receipt after 48 hours and gives the place back, and nothing sooner', function (): void {
    $order = placesPurchase(User::factory()->create(), $this->item, $this->coupon->code);

    $this->travel(47)->hours();
    expect(app(ExpireUnpaidCouponOrders::class)->handle())->toBe(0);

    $this->travel(2)->hours();
    expect(app(ExpireUnpaidCouponOrders::class)->handle())->toBe(1);

    $order->refresh();
    expect($order->status)->toBe('rejected')
        ->and($order->rejection_reason)->toBe(ExpireUnpaidCouponOrders::REASON)
        ->and((int) $this->coupon->refresh()->redemptions_count)->toBe(0);

    // A second run moves nothing.
    expect(app(ExpireUnpaidCouponOrders::class)->handle())->toBe(0)
        ->and((int) $this->coupon->refresh()->redemptions_count)->toBe(0);
});

it('leaves an order with a receipt alone, however old', function (): void {
    $order = placesPurchase(User::factory()->create(), $this->item, $this->coupon->code);
    receiptUploaded($order);

    $this->travel(5)->days();

    expect(app(ExpireUnpaidCouponOrders::class)->handle())->toBe(0)
        ->and($order->refresh()->status)->toBe('under_review');
});

/*
| The two races the security review named (2026-10-29), each pinned by putting
| the database in the state the race leaves behind.
*/
it('takes a place once however many times it is asked — two uploads racing', function (): void {
    $order = placesPurchase(User::factory()->create(), $this->item, $this->coupon->code);
    app(CouponPlaces::class)->release($order);

    app(CouponPlaces::class)->reclaim($order);
    app(CouponPlaces::class)->reclaim($order);

    expect((int) $this->coupon->refresh()->redemptions_count)->toBe(1);
});

it('never approves an order on a place it gave back — a rejection and an upload racing', function (): void {
    $order = placesPurchase(User::factory()->create(), $this->item, $this->coupon->code);

    // The race's outcome: under review, place released, and the code's only place gone to someone else.
    receiptUploaded($order);
    app(CouponPlaces::class)->release($order);
    placesPurchase(User::factory()->create(), $this->item, $this->coupon->code);

    expect(fn () => app(ApproveOrder::class)->handle($order->refresh(), $this->owner))
        ->toThrow(DomainException::class, CouponPlaces::RECLAIM_REFUSAL);

    expect($order->refresh()->status)->toBe('under_review')
        ->and((int) $this->coupon->refresh()->redemptions_count)->toBe(1);
});

it('approves such an order when a place is still free, taking it', function (): void {
    $this->coupon->forceFill(['max_redemptions' => 5])->save();
    $order = placesPurchase(User::factory()->create(), $this->item, $this->coupon->code);

    receiptUploaded($order);
    app(CouponPlaces::class)->release($order);

    expect(app(ApproveOrder::class)->handle($order->refresh(), $this->owner)->status)->toBe('approved')
        ->and((int) $this->coupon->refresh()->redemptions_count)->toBe(1);
});
