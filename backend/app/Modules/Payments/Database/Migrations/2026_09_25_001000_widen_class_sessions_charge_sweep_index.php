<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `class_sessions (charged_at, delivered_at, workspace_id)` replaces
 * `(charged_at, workspace_id)`.
 *
 * `ChargeUnbilledDeliveriesJob` runs every fifteen minutes and asks
 * `charged_at IS NULL AND delivered_at IS NOT NULL`. On the old index the first
 * half is a ref on every session never charged — every future session, every
 * cancelled one, every one abandoned before delivery, a set that only grows —
 * and each of those rows was then read to test `delivered_at`. With
 * `delivered_at` second the sweep is a range over the delivered-and-unbilled
 * few, answered from the index alone (`workspace_id` and the implicit `id` are
 * the only columns it selects).
 *
 * ⚠️ THE OLD INDEX HAS OTHER READERS, AND THE NEW ONE STILL SERVES THEM.
 * `ReconcileCreditBalancesJob` asks `charged_at IS NOT NULL` twice (a count and a
 * `chunkById` selecting `id, workspace_id`). Both need only the LEADING column,
 * which is unchanged, and the new index still carries `workspace_id` and the
 * primary key — so it covers them exactly as the old one did.
 *
 * Added first, dropped second, each in its own statement: at no moment is the
 * sweep left with no index at all, and SQLite rebuilds the table for any
 * multi-alteration. The drop is guarded on the generated name the 2026-08-08
 * migration produced; a production index named anything else is left alone
 * rather than failing the deploy (it is then merely redundant).
 *
 * Hand-named, 33 characters, well under MySQL's 64.
 */
return new class extends Migration
{
    private const INDEX = 'class_sessions_charge_sweep_index';

    private const OLD = 'class_sessions_charged_at_workspace_id_index';

    public function up(): void
    {
        if (! Schema::hasIndex('class_sessions', self::INDEX)) {
            Schema::table('class_sessions', function (Blueprint $table): void {
                $table->index(['charged_at', 'delivered_at', 'workspace_id'], self::INDEX);
            });
        }

        if (Schema::hasIndex('class_sessions', self::OLD)) {
            Schema::table('class_sessions', fn (Blueprint $table) => $table->dropIndex(self::OLD));
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('class_sessions', self::OLD)) {
            Schema::table('class_sessions', function (Blueprint $table): void {
                $table->index(['charged_at', 'workspace_id'], self::OLD);
            });
        }

        if (Schema::hasIndex('class_sessions', self::INDEX)) {
            Schema::table('class_sessions', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
