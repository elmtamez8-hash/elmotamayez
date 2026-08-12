<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Clears the way for unique(provider, reference) — WITHOUT deleting a row.
 *
 * Any database where ApproveOrder once lost a race carries two rows with the
 * same (provider, reference), and the index in the next migration would fail on
 * them. 016's lesson, literally: a unique index added to rows that already
 * violate it fails on live data and passes on an empty local one.
 *
 * ⚠️ THE SURVIVOR IS CHOSEN BY A STATED RULE — the lowest id, which is the
 * earliest write — and THE LOSER IS NOT DELETED. It keeps every column it had
 * and only its reference is renumbered, with the original preserved in the
 * payload. Deleting a financial row inside the module whose own FR-027 forbids
 * editing its ledger is a contradiction in both directions; and a duplicate is
 * evidence of a race that someone may need to read later.
 *
 * The suffix carries the id, so the new reference is unique by construction
 * without a second lookup, and it is obvious on sight that it was written here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicated = DB::table('payment_transactions')
            ->select('provider', 'reference', DB::raw('MIN(id) as survivor_id'), DB::raw('COUNT(*) as row_count'))
            ->whereNotNull('reference')
            ->groupBy('provider', 'reference')
            ->having('row_count', '>', 1)
            ->get();

        $renumbered = 0;

        foreach ($duplicated as $group) {
            $losers = DB::table('payment_transactions')
                ->where('provider', $group->provider)
                ->where('reference', $group->reference)
                ->where('id', '>', $group->survivor_id)
                ->get(['id', 'payload']);

            foreach ($losers as $loser) {
                $payload = json_decode((string) ($loser->payload ?? '{}'), true);

                if (! is_array($payload)) {
                    $payload = [];
                }

                // The reason lives on the row, not only in a log line: this is
                // the only record that the reference it was written with is not
                // the reference it now carries.
                $payload['platform_note'] = [
                    'migration' => '2026_08_11_000400_dedupe_payment_transaction_references',
                    'original_reference' => $group->reference,
                    'reason' => 'Duplicate (provider, reference) predating the unique index. '
                        .'Survivor is the lowest id; this row was renumbered, never deleted.',
                ];

                DB::table('payment_transactions')
                    ->where('id', $loser->id)
                    ->update([
                        'reference' => $group->reference.'-dup-'.$loser->id,
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ]);

                $renumbered++;
            }
        }

        Log::info('007 dedupe: payment_transactions (provider, reference)', [
            'groups' => $duplicated->count(),
            'renumbered' => $renumbered,
        ]);
    }

    public function down(): void
    {
        // Not reversed. Restoring the original references would recreate the
        // collision the next migration's index forbids.
    }
};
