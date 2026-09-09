<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `credit_purchases.order_id` had no index at all.
 *
 * ⚠️ AND IT IS READ ON EVERY PAGE OF `/orders`. `Order::creditPurchase()` is
 * eager-loaded by `OrderController::index()` (paginated fifteen at a time) and by
 * `show()`, which compiles to `WHERE order_id IN (…15 ids…)` — a FULL SCAN of a
 * table that grows with every sale on the platform, on the screen every buyer
 * opens to find their receipt. The table carried exactly two indexes,
 * `(workspace_id, purchased_at)` and `credit_balance_id`, and neither serves it.
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
