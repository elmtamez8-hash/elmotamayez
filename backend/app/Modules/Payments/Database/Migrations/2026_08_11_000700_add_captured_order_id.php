<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The real invariant, at the engine: ONE captured transaction per order.
 *
 * ⚠️ A COLUMN, NOT A PARTIAL INDEX — the owner's decision, 2026-08-11, and the
 * two alternatives are both broken:
 *
 *   unique(order_id) WHERE status = 'captured'
 *       MySQL 8 has no partial indexes; SQLite has had them since 3.8. So every
 *       local run is green and the deploy fails — the NFR-012 family exactly.
 *
 *   unique(order_id), plain
 *       Breaks retry. An order whose first payment failed could never be paid
 *       again, and Initiated/Pending cannot coexist with Captured.
 *
 *   captured_order_id + unique  ✅
 *       Portable, because NULL does not collide with NULL — the same property
 *       that makes a nullable `reference` dangerous is the mechanism here.
 *
 * ⚠️ ITS LIFECYCLE IS PART OF THE DECISION, and without it the column is worse
 * than its absence:
 *   - written `= order_id` inside the SAME atomic conditional UPDATE that moves
 *     the status to captured, never in a second call — between two calls is a
 *     window;
 *   - cleared to NULL by ReversePayment along with the move to `reversed`.
 *     Without the clear, a student whose payment was reversed by a bank dispute
 *     can NEVER pay for that order again — silently, permanently;
 *   - NULL in every other state (initiated · pending · failed · expired ·
 *     mismatch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('captured_order_id')->nullable()->after('order_id');
            $table->unique('captured_order_id');
        });

        // Backfill from what survived the previous migration. Safe by then and
        // only by then: run before the dedupe, this same statement would throw
        // on the second capture of a duplicated order.
        DB::table('payment_transactions')
            ->where('status', 'captured')
            ->select('id', 'order_id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('payment_transactions')
                        ->where('id', $row->id)
                        ->update(['captured_order_id' => $row->order_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique(['captured_order_id']);
            $table->dropColumn('captured_order_id');
        });
    }
};
