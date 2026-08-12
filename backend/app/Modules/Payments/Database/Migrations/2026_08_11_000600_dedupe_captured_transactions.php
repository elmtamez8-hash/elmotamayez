<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One captured transaction per order — enforced by the next migration's index,
 * so any order that already holds two must be resolved first.
 *
 * ⚠️ THE LOSER IS DEMOTED, NEVER DELETED. It moves to `mismatch`, which is
 * exactly what it is: a captured transaction that cannot be reconciled with the
 * one the platform is treating as authoritative. Deleting it would destroy a
 * financial record in the module whose FR-027 forbids editing one — and the
 * duplicate is the only surviving evidence that the double capture happened.
 *
 * The survivor is the lowest id — the earliest capture. Stated here rather than
 * left to whatever order the engine returns, because "the first one that was
 * paid" is a fact about the money and a query plan is not.
 *
 * The status is written as a literal string, not PaymentStatus::Mismatch: a
 * migration that imports an enum breaks the day the enum is renamed, and this
 * one must keep replaying for the lifetime of the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicated = DB::table('payment_transactions')
            ->select('order_id', DB::raw('MIN(id) as survivor_id'), DB::raw('COUNT(*) as row_count'))
            ->where('status', 'captured')
            ->groupBy('order_id')
            ->having('row_count', '>', 1)
            ->get();

        $demoted = 0;

        foreach ($duplicated as $group) {
            $losers = DB::table('payment_transactions')
                ->where('order_id', $group->order_id)
                ->where('status', 'captured')
                ->where('id', '>', $group->survivor_id)
                ->get(['id', 'payload']);

            foreach ($losers as $loser) {
                $payload = json_decode((string) ($loser->payload ?? '{}'), true);

                if (! is_array($payload)) {
                    $payload = [];
                }

                // On the row, because `failure_reason` does not exist yet — it
                // arrives two migrations later, and a reason recorded only in a
                // log is a reason nobody reading the row will find.
                $payload['platform_note'] = [
                    'migration' => '2026_08_11_000600_dedupe_captured_transactions',
                    'previous_status' => 'captured',
                    'authoritative_transaction_id' => $group->survivor_id,
                    'reason' => 'Second capture on one order, predating unique(captured_order_id). '
                        .'Survivor is the earliest capture; this row was demoted to mismatch, never deleted.',
                ];

                DB::table('payment_transactions')
                    ->where('id', $loser->id)
                    ->update([
                        'status' => 'mismatch',
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ]);

                $demoted++;
            }
        }

        Log::info('007 dedupe: captured payment_transactions per order', [
            'orders' => $duplicated->count(),
            'demoted' => $demoted,
        ]);
    }

    public function down(): void
    {
        // Not reversed. Promoting the demoted rows back to captured would
        // recreate two captures on one order — the state the next migration's
        // index exists to make impossible.
    }
};
