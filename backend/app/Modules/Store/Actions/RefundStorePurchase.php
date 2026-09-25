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
    /**
     * The refusal a printed copy gets once it has left the shelf (owner decision
     * 2026-09-25). A constant so the screen's flag and this door cannot drift.
     */
    public const PRINTED_REFUSAL = 'هذه نسخة مطبوعة دخلت مرحلة الشحن، ولا يُسترَدّ ثمنها من الموقع. تواصل مع إدارة المنصة لترتيب الإرجاع.';

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

        /*
        | ⛔ A PRINTED COPY THAT HAS LEFT THE SHELF IS NOT REFUNDED FROM THE SITE
        | (owner decision 2026-09-25). The two conditions below were written for a
        | FILE: «not opened» is meaningless for a book, so a buyer could be sent
        | the parcel, keep it, and press «استرداد» inside the window — money back
        | and the book in hand, while the old code put a copy «back on the shelf»
        | that was sitting in their house. A physical return needs a person: the
        | parcel has to come back before the money does, so the site sends the
        | buyer to the administration instead.
        |
        | Before fulfilment nothing has moved — no stock was taken and no parcel
        | packed — so the ordinary refund still applies there.
        */
        if ($purchase->printedCopyHasLeftTheShelf()) {
            throw new DomainException(self::PRINTED_REFUSAL);
        }

        if ($purchase->first_accessed_at !== null) {
            throw new DomainException('فُتِح هذا الملف، ولم يعد الاسترداد متاحاً.');
        }

        $deadline = $purchase->created_at?->addHours(StoreSettings::refundWindowHours());

        if ($deadline === null || $deadline->isPast()) {
            throw new DomainException('انتهت مهلة الاسترداد لهذا الطلب.');
        }

        DB::transaction(function () use ($purchase): void {
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

            // ⚠️ `withoutWorkspaceScope()`: this runs in the BUYER's request, and a
            // student stamped with another teacher's `last_workspace_id` would AND
            // that workspace on — 0 rows, while `refunded_at` above still commits,
            // so the buyer reads «refunded» over an order that never moved. The
            // purchase row was already proven the buyer's; the order is its key.
            Order::withoutWorkspaceScope()
                ->whereKey($purchase->order_id)
                ->update(['status' => 'refund_due']);

            /*
            | ⚠️ NOTHING GOES BACK ON THE SHELF, AND THAT IS NOW TRUE BY
            | CONSTRUCTION. Only a fulfilled printed purchase ever took stock, and
            | that purchase is refused above — so every refund that reaches this
            | line either never took a copy (not yet fulfilled) or is a file with
            | no stock at all. A restock here would add a book that does not
            | exist; a copy that really comes back is put back by whoever
            | receives the parcel.
            */
        });

        return $purchase->refresh();
    }
}
