<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two indexes the subscription queue and the subscriber lookup need (spec 027 · T001).
 *
 * ⚠️ `orders` ALREADY CARRIES `(workspace_id, status)` AND `(workspace_id, kind,
 * status)`, AND BOTH ARE UNREACHABLE HERE. The staff queue is a PLATFORM read —
 * it opens with `withoutWorkspaceScope()` — which removes the leading column of
 * each, and a composite is only usable from its leading column. MySQL 8's index
 * skip-scan cannot rescue it either: that needs a low-cardinality skipped prefix,
 * and `workspace_id` is one value per teacher on the platform. So the screen a
 * finance officer opens repeatedly during a review session was two full scans of
 * the fastest-growing table in the product per press — Filament's paginator runs
 * its own `COUNT(*)` over the identical predicate.
 *
 * ⚠️ `created_at` IS THE THIRD COLUMN AND DOES NOT REMOVE THE FILESORT. The cut
 * is `status IN ('pending','under_review')` — two ranges — so index order is not
 * query order. It is there so the sort runs over the pending set (tens of rows)
 * rather than over the table. A `(kind, created_at)` index that sorted but did
 * not cut would walk every approved subscription order ever written.
 *
 * ⚠️ AND `subscriptions` HAS NO GOOD CHOICE WITHOUT THE SECOND INDEX. Asked for
 * one teacher's live subscribers, the optimiser must pick between the
 * `workspace_id` foreign key — every subscription that teacher EVER sold, since
 * nothing prunes expired rows — and `(status, effective_ends_on)`, the nightly
 * sweep's index, which returns every live subscription on the PLATFORM and
 * filters the workspace out afterwards. Both grow the wrong way, and the first
 * degrades monotonically. Equality, equality, range is the shape the read has.
 *
 * ⚠️ `effective_ends_on`, NEVER `ends_on`. The column this index serves is the
 * one every predicate in the tree reads — `ends_on` is what the plan sold and
 * never moves, while a freeze extends `effective_ends_on`. An index on the
 * frozen column would be an index nothing asks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['kind', 'status', 'created_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['workspace_id', 'status', 'effective_ends_on']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'status', 'effective_ends_on']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status', 'created_at']);
        });
    }
};
