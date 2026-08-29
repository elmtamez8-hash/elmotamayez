<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Store\Models\StoreItem;
use App\Shared\Actions\Action;

/**
 * Take `$quantity` copies off the shelf, or refuse (spec 011 · US1 · FR-006).
 *
 * ⚠️ ONE ATOMIC CONDITIONAL UPDATE — the seat idiom. `count()` then `insert()` is
 * the definition of the race; `lockForUpdate()` is a no-op on SQLite, so a test
 * written around it passes locally and proves nothing about the MySQL it will run
 * on. The `WHERE` is both the check and the claim.
 *
 * ⚠️ AND THE BRANCH ON `kind` COMES FIRST. `stock` is `null` for a digital item,
 * and `stock >= :qty` against NULL is NULL — so a claim that reads the stock
 * without asking what kind of thing it is matches zero rows and reports
 * «نفد المخزون» about a file that cannot run out. Every copy sold, for ever,
 * with the shelf full.
 */
class ClaimStock extends Action
{
    /** @return bool whether the copies were taken */
    public function handle(StoreItem $item, int $quantity): bool
    {
        if (! $item->kind->isStocked()) {
            // Nothing to claim. A file does not run out, and there is no row to
            // move — reporting success is the truthful answer, not a shortcut.
            return true;
        }

        if ($quantity < 1) {
            return false;
        }

        $claimed = StoreItem::query()
            ->withoutWorkspaceScope()
            ->whereKey($item->getKey())
            ->where('stock', '>=', $quantity)
            // `decrement()` compiles to `SET stock = stock - ?` in ONE statement,
            // so the predicate above and the write below are the same trip.
            ->decrement('stock', $quantity);

        if ($claimed === 0) {
            return false;
        }

        // The caller usually goes on to read the item; leaving it holding the
        // pre-claim number is how a screen tells the next buyer there is one
        // more copy than there is.
        $item->refresh();

        return true;
    }

    /**
     * Put copies back — a refund, or a purchase that failed after the claim.
     *
     * ⚠️ UNCONDITIONAL BY DESIGN, and it is the one asymmetry worth writing down:
     * a claim may be refused, a release may not. Refusing to return stock because
     * some ceiling was crossed leaves copies that exist on a shelf that says they
     * do not.
     */
    public function release(StoreItem $item, int $quantity): void
    {
        if (! $item->kind->isStocked() || $quantity < 1) {
            return;
        }

        StoreItem::query()
            ->withoutWorkspaceScope()
            ->whereKey($item->getKey())
            ->increment('stock', $quantity);

        $item->refresh();
    }
}
