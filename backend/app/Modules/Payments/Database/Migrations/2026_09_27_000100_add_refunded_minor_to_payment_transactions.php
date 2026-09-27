<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a reversed payment actually goes back to the payer.
 *
 * ⛔ UNTIL 2026-09-27 THE ANSWER WAS IMPLICIT, AND IT WAS «ALL OF IT». A
 * reversal flipped the capture to `reversed` and the whole `amount_minor` left
 * the collection report — which was true while every reversal was a full one.
 * The owner's decision of 2026-09-27 makes cancelling a subscription refund only
 * the unused part (days for a duration plan, sessions for an hours plan), so
 * the part the payer gets back is now a number of its own, written once by
 * `ReversePayment` and read by finance.
 *
 * ⚠️ BACKFILLED for every row already `reversed`: each of them WAS a full
 * reversal, and a null there would read as «nothing refunded» on the finance
 * screens. Minor units, a signed big integer, as `amount_minor` is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->bigInteger('refunded_minor')->nullable()->after('amount_minor');
        });

        DB::table('payment_transactions')
            ->where('status', 'reversed')
            ->whereNull('refunded_minor')
            ->update(['refunded_minor' => DB::raw('amount_minor')]);
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropColumn('refunded_minor');
        });
    }
};
