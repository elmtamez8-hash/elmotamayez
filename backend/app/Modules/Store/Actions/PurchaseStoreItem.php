<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Models\User;
use App\Modules\Payments\Actions\RedeemCoupon;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\DiscountResolver;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\Shipment;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;
use App\Modules\Store\Support\StoreSettings;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Buy something from the store (spec 011 · US1 · FR-005 · FR-007).
 *
 * ⚠️ NOTHING IS DELIVERED HERE AND NO STOCK MOVES. A manual bank transfer takes
 * days to be approved, so this writes an `Order(kind: store)` and a bridge row
 * and stops. `FulfilOnPaymentApproved` does the rest when the money is
 * witnessed — which is also why the sold-out branch has to exist (FR-006ب): the
 * last copy can be gone by the time the transfer clears, and «you lost the race,
 * nothing follows» is the wrong last word about an order somebody has paid.
 *
 * ⚠️ `workspace_id` IS ASSIGNED EXPLICITLY, FROM THE ITEM. `BelongsToWorkspace`
 * fills it only when the context is non-null, and a student is a member of no
 * workspace — so on the buyer's path the trait writes nothing and the column
 * lands empty, silently, on every row. Precedent: `credit_balances`.
 *
 * ⚠️ AND THE ADDRESS IS ASKED BEFORE THE MONEY. Taking payment for a parcel with
 * nowhere to send it turns a validation error into a refund.
 */
class PurchaseStoreItem extends Action
{
    public function __construct(
        private readonly DiscountResolver $discounts,
        private readonly RedeemCoupon $redeem,
    ) {}

    public function handle(User $buyer, PurchaseData $data): StoreOrder
    {
        $item = $this->resolveItem($data->itemUuid);

        if ($data->quantity < 1) {
            throw new DomainException('الكمية يجب أن تكون واحدة على الأقل.');
        }

        if ($item->kind->needsAddress() && ! $data->hasAddress()) {
            throw new DomainException('النسخة المطبوعة تحتاج اسم المستلم ورقمه وعنوانه.');
        }

        /*
        | ⚠️ A CHECK, NOT A CLAIM. The stock is read here only so a buyer is not
        | invited to pay for something visibly gone; the copies are taken at
        | fulfilment, atomically. Claiming now would hold a copy for every
        | abandoned checkout on the platform, and there is nothing to release it.
        */
        if ($item->kind->isStocked() && (int) $item->stock < $data->quantity) {
            throw new DomainException('لم يتبقَّ من هذا المنتج ما يكفي.');
        }

        $workspaceId = (int) $item->workspace_id;
        $goods = (int) $item->price_minor * $data->quantity;
        $shipping = $item->kind->isStocked() ? (int) $item->shipping_fee_minor : 0;
        $commission = StoreSettings::commissionOn($goods);

        /*
        | ⚠️ THE DISCOUNT COMES OFF THE GOODS AND NOT OFF THE POSTAGE. The
        | shipping fee is money the teacher hands to a courier; discounting it
        | would make the platform's campaign pay part of somebody else's invoice.
        |
        | ⚠️ AND IT IS RESOLVED AGAINST THE ITEM'S WORKSPACE, never against
        | `WorkspaceContext::id()` — which is null for every student, so reading
        | the context here would let only platform coupons ever match.
        */
        $discount = $this->discounts->resolve(
            $buyer,
            $workspaceId,
            $goods,
            $data->couponCode,
            CouponScope::StoreItem,
            (string) $item->uuid,
        );

        return DB::transaction(function () use (
            $buyer, $data, $item, $workspaceId, $goods, $shipping, $commission, $discount
        ): StoreOrder {
            $order = Order::create([
                'workspace_id' => $workspaceId,
                'user_id' => $buyer->getKey(),
                'course_id' => $item->course_id,
                'kind' => OrderKind::Store,
                'amount_minor' => $goods - $discount->amountMinor + $shipping,
                'currency' => $item->currency,
                'provider' => 'manual',
                'status' => 'pending',
            ]);

            $storeOrder = StoreOrder::create([
                'workspace_id' => $workspaceId,
                'order_id' => $order->getKey(),
                'store_item_id' => $item->getKey(),
                'buyer_user_id' => $buyer->getKey(),
                'quantity' => $data->quantity,
                // Per unit, so a later price change cannot rewrite what this
                // buyer agreed to.
                'unit_price_minor' => (int) $item->price_minor,
                'discount_minor' => $discount->amountMinor,
                // Frozen on the line: the teacher may raise the postage
                // tomorrow, and recomputing it would rewrite what this buyer
                // agreed to — and would put a number on their screen that no
                // longer matches the transfer the order is waiting for.
                'shipping_minor' => $shipping,
                /*
                | Per LINE, both of them — the split is frozen at the moment of
                | sale, because a rate read afterwards is a different number.
                |
                | ⚠️ THE DISCOUNT COMES ENTIRELY OUT OF THE PLATFORM'S SHARE, AND
                | THAT SHARE IS ALLOWED TO GO NEGATIVE (FR-010). A coupon must not
                | touch what the teacher is owed — they never agreed to the
                | campaign and did not set its price — so `teacher_net_minor` is
                | computed from the LIST price and does not move. List 50, teacher
                | 45, coupon −10 ⇒ the buyer pays 40 and the platform's share is
                | −5: a decision the platform made when it created the coupon,
                | which is why the column is a signed `bigInteger`.
                */
                'commission_minor' => $commission - $discount->amountMinor,
                'teacher_net_minor' => $goods - $commission + $shipping,
                'currency' => $item->currency,
            ]);

            if ($item->kind->needsAddress()) {
                /*
                | The shipment row exists from the moment of purchase, `pending`,
                | so the address travels with the order rather than being asked
                | for again days later — by which time the person who typed it may
                | not be at the screen.
                */
                Shipment::create([
                    'workspace_id' => $workspaceId,
                    'store_order_id' => $storeOrder->getKey(),
                    'recipient_name' => (string) $data->recipientName,
                    'phone' => (string) $data->phone,
                    'address_line' => (string) $data->addressLine,
                    'notes' => $data->notes,
                ]);
            }

            // Inside the transaction on purpose: the claim on the coupon's
            // ceiling throws when somebody took the last place in between, and
            // the rollback is what stops the order existing at a price the
            // coupon no longer justifies.
            $this->redeem->handle($order, $discount);

            return $storeOrder;
        });
    }

    /**
     * ⚠️ RESOLVED INSIDE THE ACTION, NEVER BY ROUTE-MODEL BINDING. `StoreItem`
     * carries `BelongsToWorkspace`, which protects NOTHING on a student's path:
     * a student belongs to no workspace, `WorkspaceContext::id()` is null and
     * `WorkspaceScope::apply()` adds no condition at all. An implicit `{item}`
     * would resolve any teacher's product, including an inactive one.
     */
    private function resolveItem(string $uuid): StoreItem
    {
        $item = StoreItem::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->first();

        if ($item === null) {
            throw new DomainException('هذا المنتج غير متاح.');
        }

        return $item;
    }
}
