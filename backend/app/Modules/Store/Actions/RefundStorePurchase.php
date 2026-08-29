<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Models\User;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Models\StoreOrder;
use App\Modules\Store\Support\StoreSettings;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Give the money back (spec 011 · US1 · decision C4).
 *
 * ⚠️ TWO CONDITIONS, AND EITHER ALONE IS A DIFFERENT PRODUCT. Forty-eight hours
 * with no «opened» test is a free copy of every book on the platform: buy it,
 * read it, ask for the money back the same evening. «Not opened» with no window
 * is a refund available for ever on a file the buyer may have kept.
 *
 * ⚠️ AND THE WINDOW IS MEASURED FROM THE PURCHASE, NOT FROM FULFILMENT. A manual
 * transfer can take days to approve, and a clock started at approval would let a
 * buyer who has waited a week get their refund window a week late — which is
 * defensible — but it would ALSO mean an order approved months after it was
 * placed is still refundable, which is not. The buyer's own decision to buy is
 * what the window belongs to.
 */
class RefundStorePurchase extends Action
{
    public function __construct(private readonly ClaimStock $stock) {}

    public function handle(string $purchaseUuid, User $buyer): StoreOrder
    {
        // Ownership resolved here, never by route-model binding: `StoreOrder`
        // carries `BelongsToWorkspace`, which protects nothing on a student's
        // path — the context is null and the scope adds no condition at all.
        $purchase = StoreOrder::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $purchaseUuid)
            ->where('buyer_user_id', $buyer->getKey())
            ->first();

        if ($purchase === null) {
            throw new DomainException('لا تملك صلاحية لهذا الإجراء.');
        }

        if ($purchase->refunded_at !== null) {
            throw new DomainException('استُرِدَّ ثمن هذا الطلب من قبل.');
        }

        if ($purchase->first_accessed_at !== null) {
            throw new DomainException('فُتِح هذا الملف، ولم يعد الاسترداد متاحاً.');
        }

        $deadline = $purchase->created_at?->addHours(StoreSettings::refundWindowHours());

        if ($deadline === null || $deadline->isPast()) {
            throw new DomainException('انتهت مهلة الاسترداد لهذا الطلب.');
        }

        $item = $purchase->item()->withoutWorkspaceScope()->first();

        DB::transaction(function () use ($purchase, $item): void {
            /*
            | ⚠️ CLAIMED, NOT ASSIGNED. Two taps on «استرداد» would otherwise both
            | read `refunded_at` as null, both write, and both put the stock back
            | — inventing a copy that does not exist.
            */
            $claimed = StoreOrder::query()
                ->withoutWorkspaceScope()
                ->whereKey($purchase->getKey())
                ->whereNull('refunded_at')
                ->update(['refunded_at' => now()]);

            if ($claimed === 0) {
                return;
            }

            Order::query()
                ->whereKey($purchase->order_id)
                ->update(['status' => 'refund_due']);

            // Only a fulfilled purchase ever took stock. Releasing against one
            // that was never delivered would add a copy to the shelf.
            if ($item !== null && $purchase->fulfilled_at !== null) {
                $this->stock->release($item, $purchase->quantity);
            }
        });

        return $purchase->refresh();
    }
}
