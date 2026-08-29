<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T061 — every use of a coupon, by whom and on what (FR-015).
 *
 * ⚠️ `unique(coupon_id, order_id)` AND BOTH COLUMNS `NOT NULL`. `NULL` never
 * equals `NULL` on either engine, so a unique index carrying a nullable column
 * does not bite — the guard would simply not exist, and one order could redeem
 * the same coupon any number of times. This tree has now paid for that lesson
 * four times (`concept_stats.lesson_id`, `unlock_rules.course_id`,
 * `award_entries.reversal_of_id`, `feature_flags.workspace_id`), and here there
 * is no sentinel to reach for: an order id of 0 is not an order.
 *
 * ⚠️ THE ROW IS WRITTEN BEFORE THE COUNTER MOVES. `CreditLedger`'s order — the
 * entry first, the balance second. Reversed, a redelivered event has its INSERT
 * swallowed by this very index and then increments the counter a second time:
 * the coupon runs out early, `redemptions_count` disagrees with `COUNT(*)` for
 * ever, and nothing anywhere notices.
 *
 * ⚠️ `workspace_id` IS ASSIGNED EXPLICITLY IN THE ACTION, from the order.
 * `BelongsToWorkspace` fills it only `if ($workspaceId !== null)` and a student
 * is a member of no workspace, so on the buyer's path — which is every path
 * that reaches this table — the trait would write nothing at all and the column
 * would land empty on every row, silently. Precedent: `credit_balances`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('workspace_id');

            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id');

            // What was actually taken off, in minor units — not the coupon's
            // own `value`. A fixed coupon is clamped at the line total and a
            // percentage depends on the line, so the coupon's value answers a
            // different question from the one FR-015 asks.
            $table->bigInteger('discount_minor');

            $table->timestamps();

            $table->unique(['coupon_id', 'order_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
