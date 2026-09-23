<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two platform-wide settlement sweeps had no index, and five workspace
 * indexes were a strict prefix of another.
 *
 * ADDED — `teaching_units (status, settlement_period_id, workspace_id,
 * teacher_profile_id)`. Every scoped read of this table (the statement, the
 * close, the export, the clearance) already starts with `workspace_id` and is
 * served by `teaching_units_statement_index`. The two readers that are NOT are
 * the ones declared `withoutWorkspaceScope()`, so they walked the whole table
 * every run:
 *
 *   - `CloseDueSettlementPeriodsJob`: `status = accrued AND settlement_period_id
 *     IS NULL`, selecting `workspace_id, teacher_profile_id` — both equality
 *     shaped (`IS NULL` is an equality to a B-tree), and the two selected columns
 *     appended so the whole query is answered from the index;
 *   - `ReleasePendingUnitsJob`: `status = pending_package` — the leftmost column.
 *
 * DROPPED — the single-column `workspace_id` index on `settlement_rates`,
 * `rate_change_requests`, `teaching_units`, `settlement_periods` and
 * `ledger_entries`. Each is a strict leftmost prefix of a composite on the same
 * table that begins with `workspace_id`, and none is a foreign key (the column is
 * a bare `unsignedBigInteger` on all five), so the composite answers every query
 * the single column did and MySQL loses nothing it needs for a constraint. Each
 * was a second B-tree written on every insert for no reader.
 *
 * Names are written by hand and every one is under MySQL's 64 characters —
 * `SchemaIdentifierLengthTest` reads them back.
 */
return new class extends Migration
{
    private const SWEEP_INDEX = 'teaching_units_status_period_index';

    /** The redundant single-column indexes, by table, with their generated names. */
    private const REDUNDANT = [
        'settlement_rates' => 'settlement_rates_workspace_id_index',
        'rate_change_requests' => 'rate_change_requests_workspace_id_index',
        'teaching_units' => 'teaching_units_workspace_id_index',
        'settlement_periods' => 'settlement_periods_workspace_id_index',
        'ledger_entries' => 'ledger_entries_workspace_id_index',
    ];

    public function up(): void
    {
        if (! Schema::hasIndex('teaching_units', self::SWEEP_INDEX)) {
            Schema::table('teaching_units', function (Blueprint $table): void {
                $table->index(
                    ['status', 'settlement_period_id', 'workspace_id', 'teacher_profile_id'],
                    self::SWEEP_INDEX,
                );
            });
        }

        // Each in its own statement: a multi-alteration on SQLite is a table
        // rebuild, and on MySQL one failure would leave the rest undone anyway.
        foreach (self::REDUNDANT as $table => $index) {
            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }
        }
    }

    public function down(): void
    {
        foreach (self::REDUNDANT as $table => $index) {
            if (! Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index('workspace_id', $index));
            }
        }

        if (Schema::hasIndex('teaching_units', self::SWEEP_INDEX)) {
            Schema::table('teaching_units', fn (Blueprint $table) => $table->dropIndex(self::SWEEP_INDEX));
        }
    }
};
