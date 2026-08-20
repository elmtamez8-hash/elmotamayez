<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The leaderboard — DERIVED, never a source (FR-025 · SC-011).
 *
 * An indexed table rather than a Redis sorted set, and the trade-off is written
 * out in plan.md § Complexity Tracking. The short version: Redis here IS the
 * queue server, so an eviction at maxmemory would wipe a live board in
 * mid-week with no error anywhere — and a second source of truth needs a rebuild
 * path that only ever runs on the day of the disaster. ZREVRANK is the one thing
 * a ZSET gives that this does not, and freezing the rank at rollup replaces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /** `platform` · `grade:{slug}` · `subject:{id}` · `workspace:{id}` · `course:{id}` · `lesson:{id}` */
            $table->string('scope_key', 80);

            /** `w:2026-W34` (week starting SUNDAY) or `t:2026-1`. */
            $table->string('period_key', 16);

            $table->unsignedBigInteger('user_id');

            /*
            | ⚠️ SIGNED, AND THIS ONE COST A DESIGN REVIEW TO SPOT.
            |
            | A week whose net is negative — a penalty larger than what was earned
            | — raises ERROR 1264 on an unsigned column and the rebuild job dies
            | half-way through, leaving every board after the failure point empty.
            | SQLite stores -40 without complaint, so no local test can ever
            | reproduce it: the engine that fails is the one nobody runs the suite
            | against.
            */
            $table->integer('points')->default(0);

            /** Carried from the award entries, never re-derived from today's level. */
            $table->unsignedSmallInteger('level_band')->default(0);

            /*
            | ⚠️ FROZEN AT ROLLUP, via
            |   ROW_NUMBER() OVER (PARTITION BY scope_key, period_key, level_band
            |                      ORDER BY points DESC, user_id)
            |
            | `COUNT(*) WHERE points > ?` was the obvious alternative and its cost
            | IS the rank itself — the 60,000th student walks 60,000 rows, while
            | SC-008 measures p95, i.e. the deep half. The `, user_id` tie-break
            | fixes a second defect in the same stroke: points are not a unique
            | ordering, so counting gives tied students the same rank while the
            | list orders them arbitrarily — the student reads `my_rank: 7` and
            | finds their own name ninth IN THE SAME PAYLOAD.
            */
            $table->unsignedInteger('rank')->default(0);

            /*
            | Which rollup pass last wrote this row. The rebuild upserts and then
            | deletes what it did not touch; delete-then-insert would leave every
            | board EMPTY for the duration of the build, which is exactly what
            | Redis was rejected for — by the hour instead of at maxmemory.
            */
            $table->string('run_stamp', 32)->default('');

            $table->timestamps();

            $table->unique(['scope_key', 'period_key', 'user_id']);

            /** The read: one scope, one period, one band, ordered by frozen rank. */
            $table->index(['scope_key', 'period_key', 'level_band', 'rank'], 'leaderboard_read_index');

            /** The retention sweep (FR-026), which knows only the period. */
            $table->index('period_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_entries');
    }
};
