<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three indexes the spec declares (data-model §ح).
 *
 * ⚠️ THE COLLECTION REPORT'S INDEX STARTS AT `created_at`, NOT `workspace_id`,
 * and that is not an oversight. The report is platform-wide by permission, so
 * its reader declares `withoutWorkspaceScope()` — and a composite beginning with
 * a column the query does not filter on is an index the engine cannot use. The
 * alternative is worse than a slow query: WorkspaceContext::id() falls back to
 * `users.last_workspace_id` for EVERY user including a super admin, so a report
 * left scoped would quietly show one teacher's money as the platform's total,
 * and pass its test on a single-workspace fixture.
 *
 * `(status, created_at)` serves the timeout sweep, which asks for pending rows
 * older than a cutoff — status first because it is the equality.
 *
 * `credit_purchases(order_id)` is the FR-028 chain's hinge and has never been
 * indexed: the join from a payment back to what it bought is walked on every
 * approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->index(['created_at', 'status', 'method'], 'payment_transactions_report_index');
            $table->index(['status', 'created_at'], 'payment_transactions_sweep_index');
        });

        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex('payment_transactions_report_index');
            $table->dropIndex('payment_transactions_sweep_index');
        });

        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
        });
    }
};
