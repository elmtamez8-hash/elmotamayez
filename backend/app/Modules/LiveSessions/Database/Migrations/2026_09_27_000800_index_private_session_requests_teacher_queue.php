<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `private_session_requests(workspace_id, status, starts_at)` — the teacher's
 * queue.
 *
 * `PrivateSessionRequestController::queue()` filters `workspace_id = ?` and
 * `status = ?` and orders by `starts_at`, paged. The table had
 * `workspace_id` alone and `(teacher_profile_id, status)`, so the queue read
 * every request the workspace ever received and sorted them in memory to hand
 * back twenty. Equalities first, the sort column last, so the page is read in
 * index order.
 *
 * The single-column `workspace_id` index is left in place: it is now a strict
 * leading prefix of this one, but dropping it is a separate, measured change
 * (as #251 did for five others), not a side effect of adding a read path.
 *
 * Named by hand, under MySQL's 64-character limit; guarded by `hasIndex()`.
 */
return new class extends Migration
{
    private const INDEX = 'psr_workspace_status_starts_index';

    public function up(): void
    {
        if (! Schema::hasIndex('private_session_requests', self::INDEX)) {
            Schema::table('private_session_requests', fn (Blueprint $table) => $table->index(['workspace_id', 'status', 'starts_at'], self::INDEX));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('private_session_requests', self::INDEX)) {
            Schema::table('private_session_requests', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
