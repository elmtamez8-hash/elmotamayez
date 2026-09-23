<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two platform-wide billing sweeps that had no index to start from.
 *
 * `credit_holds (settled_at, class_session_id)` — `SweepStaleCreditHoldsJob`
 * and the stale-hold half of `ReconcileCreditBalancesJob` both ask
 * `settled_at IS NULL` across every workspace and then join `class_sessions`
 * on `class_session_id`. The existing `(class_session_id, settled_at)` cannot
 * start from `settled_at`, so both read every hold ever placed; unsettled
 * holds are the small, live minority, and this walks only them — covering the
 * join key so the hold rows themselves are not touched to find it.
 *
 * `credit_balances (negative_since, workspace_id)` — `EvaluateCreditLimitsJob`
 * asks `negative_since <= cutoff` with NO workspace (it is declared
 * `withoutWorkspaceScope()`), so the existing `(workspace_id, negative_since)`
 * is unusable for it: that index is a per-tenant one and this sweep is not.
 * `workspace_id` rides along because it is the only other column the sweep
 * selects. (The dormancy sweep reads `last_transaction_at`, not this column;
 * it is not what this index is for.)
 *
 * Hand-named, both well under MySQL's 64 characters.
 */
return new class extends Migration
{
    private const HOLDS = 'credit_holds_settled_session_index';

    private const BALANCES = 'credit_balances_negative_since_index';

    public function up(): void
    {
        if (! Schema::hasIndex('credit_holds', self::HOLDS)) {
            Schema::table('credit_holds', function (Blueprint $table): void {
                $table->index(['settled_at', 'class_session_id'], self::HOLDS);
            });
        }

        if (! Schema::hasIndex('credit_balances', self::BALANCES)) {
            Schema::table('credit_balances', function (Blueprint $table): void {
                $table->index(['negative_since', 'workspace_id'], self::BALANCES);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('credit_balances', self::BALANCES)) {
            Schema::table('credit_balances', fn (Blueprint $table) => $table->dropIndex(self::BALANCES));
        }

        if (Schema::hasIndex('credit_holds', self::HOLDS)) {
            Schema::table('credit_holds', fn (Blueprint $table) => $table->dropIndex(self::HOLDS));
        }
    }
};
