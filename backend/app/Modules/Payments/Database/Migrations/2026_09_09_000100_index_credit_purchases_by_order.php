<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `credit_purchases.order_id` becomes UNIQUE.
 *
 * ⛔ **TWO SENTENCES OF THIS DOCBLOCK WERE FALSE AND ARE CORRECTED HERE
 * (٢٠٢٦-٠٩-١٩).** It opened «`credit_purchases.order_id` had no index at all»
 * and went on to say «the table carried exactly two indexes». Both were wrong:
 * `_2026_08_11_001000_add_reporting_indexes` had added `index('order_id')` a
 * month earlier, naming that very column «the FR-028 chain's hinge». So this
 * migration added a SECOND index to a column that already had one, and the
 * redundant plain one is dropped by
 * `_2026_09_19_000600_drop_duplicate_credit_purchases_order_index`.
 *
 * The correction is left in place rather than the paragraph deleted: a claim of
 * ABSENCE and a claim of EXHAUSTIVENESS are the two this tree's own rule says
 * must be measured before they are written, and this is what one costs.
 *
 * ⚠️ AND THE COLUMN IS READ ON EVERY PAGE OF `/orders`. `Order::creditPurchase()`
 * is eager-loaded by `OrderController::index()` (paginated fifteen at a time) and
 * by `show()`, which compiles to `WHERE order_id IN (…15 ids…)` on a table that
 * grows with every sale on the platform, on the screen every buyer opens to find
 * their receipt.
 *
 * ⚠️ UNIQUE RATHER THAN A PLAIN INDEX, and that is a claim about the domain, not
 * a micro-optimisation: `Order::creditPurchase()` is a `HasOne`, so a second
 * purchase row against one order is already a fault the application cannot
 * express — the index says so to the engine as well, and turns the lookup into an
 * `eq_ref`. Measured before writing it: zero duplicate `order_id` values.
 *
 * A deployment that finds duplicates here must ship the plain index instead and
 * investigate the duplicates separately; a unique index cannot be added over
 * them, and silently dropping one of two real purchases is not a migration's
 * decision to make.
 *
 * No row is written or altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table): void {
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table): void {
            $table->dropUnique(['order_id']);
        });
    }
};
