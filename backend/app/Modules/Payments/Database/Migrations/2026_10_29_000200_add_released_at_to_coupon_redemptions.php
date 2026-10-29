<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a redemption's place on its coupon was GIVEN BACK (owner decision
 * 2026-10-29). A place is claimed at purchase; it now returns when the order is
 * rejected — by an officer, or by the 48-hour sweep of an order that never
 * received a receipt — and is claimed again if a receipt is later uploaded
 * onto that same order. NULL is a place held.
 *
 * The row stays: it is the record that the code was used on that order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('coupon_redemptions', 'released_at')) {
            Schema::table('coupon_redemptions', fn (Blueprint $table) => $table->timestamp('released_at')->nullable()->after('discount_minor'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('coupon_redemptions', 'released_at')) {
            Schema::table('coupon_redemptions', fn (Blueprint $table) => $table->dropColumn('released_at'));
        }
    }
};
