<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · what the buyer was charged for postage, frozen on the line.
 *
 * ⚠️ THE RESOURCE'S `total_minor` OMITTED IT, AND THAT NUMBER IS WHAT SOMEBODY
 * TRANSFERS. A printed purchase showed 50 while `orders.amount_minor` — the sum
 * the bank transfer must match — was 65. The buyer sends the number their screen
 * gave them, and the callback handler then answers `mismatch`: no delivery, no
 * refund, a case in the reconciliation report over a number this product told
 * them to send.
 *
 * ⚠️ AND IT IS FROZEN RATHER THAN READ BACK OFF `store_items`. The teacher may
 * raise the postage tomorrow; recomputing it would rewrite what a past buyer
 * agreed to, which is the reason `unit_price_minor` is copied onto this row and
 * the reason `billable_seats` is written once. A Resource that derived it from
 * an eager-loaded item would additionally answer a DIFFERENT number whenever the
 * relation was not loaded — a money field that depends on a `with()` is a money
 * field that goes quietly wrong on the next caller.
 *
 * Default 0, so every row written before this migration reads as «no postage» —
 * which is true of every digital purchase and of nothing else that exists yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table): void {
            $table->bigInteger('shipping_minor')->default(0)->after('discount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table): void {
            $table->dropColumn('shipping_minor');
        });
    }
};
