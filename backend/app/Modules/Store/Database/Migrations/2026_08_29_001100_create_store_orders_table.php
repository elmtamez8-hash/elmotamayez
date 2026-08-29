<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T030 — the bridge between an `orders` row and the thing it bought.
 *
 * ⚠️ THERE IS NO STATUS COLUMN HERE, AND THAT IS THE DESIGN. An order's
 * lifecycle belongs to `Payments`: `refund_due` is a value in `orders.status`,
 * not a second status on a bridge table. Two columns answering «where has this
 * order got to» are the two answers that drift apart, and the one that drifts
 * quietly is whichever screen reads the newer.
 *
 * ⚠️ `fulfilled_at` IS THE IDEMPOTENCY GUARD, NOT A REPORT FIELD. Fulfilment is
 * claimed with a conditional `UPDATE … WHERE fulfilled_at IS NULL` — the seat
 * idiom — and everything after it is conditional on having won. `PaymentApproved`
 * is redelivered by any queue retry, and a read-then-write there decrements the
 * stock twice for one sale.
 *
 * ⚠️ `[store_item_id]` IS NOT OPTIONAL. `withCount` is a correlated subquery
 * evaluated PER ROW — it moves the N+1 from PHP into the engine, it does not
 * remove it. A thousand products without this index is a thousand scans of the
 * orders table inside one statement: the query budget passes (it is one query)
 * while the 800ms target fails.
 *
 * ⚠️ `teacher_net_minor` IS STORED AND NEVER SENT. `ContextIsolationTest` sweeps
 * every module's Resources for `net_minor` with `str_contains`, and
 * `amount_minor` is exempt for `Payments/` alone — so the Store's own money key
 * is `total_minor`. The column exists because a commission split has to be
 * frozen at the moment of sale; a rate read later is a different number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Assigned EXPLICITLY by the Action, never left to the trait.
            // `BelongsToWorkspace` fills it `if ($workspaceId !== null)`, and a
            // student is a member of no workspace — so on the buyer's path the
            // context is null and the column would be written empty, silently.
            // Precedent: `credit_balances`.
            $table->unsignedBigInteger('workspace_id');

            // One bridge row per order. The unique index is what makes a
            // redelivered `PaymentApproved` a no-op rather than a second sale.
            $table->unsignedBigInteger('order_id')->unique();

            $table->unsignedBigInteger('store_item_id');
            $table->unsignedBigInteger('buyer_user_id');

            $table->unsignedSmallInteger('quantity');

            // Per UNIT.
            $table->bigInteger('unit_price_minor');

            // Per LINE — already multiplied by `quantity`. Written down here
            // because an example with a quantity of one settles nothing, and the
            // two readings differ by a factor nobody notices until they do.
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);
            $table->bigInteger('teacher_net_minor')->default(0);

            $table->char('currency', 3);

            $table->timestamp('fulfilled_at')->nullable();

            // Digital only, and it is what closes the refund window (C4): a book
            // that has been opened has been delivered.
            $table->timestamp('first_accessed_at')->nullable();

            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->index(['buyer_user_id', 'created_at']);
            $table->index(['workspace_id', 'created_at']);
            $table->index('store_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_orders');
    }
};
