<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step 2 of 3: fill every row.
 *
 * ⚠️ chunkById, NEVER chunk — the same defect 016 shipped and had to repair.
 * `chunk` pages with OFFSET while the predicate (`uuid IS NULL`) shrinks
 * underneath it, so every page after the first skips exactly as many rows as
 * the previous page repaired. The migration then reports success, the unique
 * index in step 3 fails on the rows it never saw, and the failure surfaces one
 * migration later than its cause. `chunkById` walks the primary key, which does
 * not move.
 *
 * Str::uuid() per row rather than a single expression in SQL: MySQL's UUID()
 * and SQLite's absence of one do not produce the same thing, and the model
 * layer that would normally supply this (HasUuid) is not running here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $filled = 0;

        DB::table('payment_transactions')
            ->whereNull('uuid')
            ->select('id')
            ->chunkById(500, function ($rows) use (&$filled): void {
                foreach ($rows as $row) {
                    DB::table('payment_transactions')
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) Str::uuid()]);

                    $filled++;
                }
            });

        $remaining = DB::table('payment_transactions')->whereNull('uuid')->count();

        // Reported, because step 3 is what pays for a miss here and by then the
        // cause is a migration away.
        Log::info('007 backfill: payment_transactions.uuid', [
            'filled' => $filled,
            'left_null' => $remaining,
        ]);
    }

    public function down(): void
    {
        // Not reversed. Nulling the column back would be indistinguishable from
        // losing the identifiers rows have since been routed by.
    }
};
