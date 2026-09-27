<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the item-analysis rollup remembers its last night (spec 008 · FR-014).
 *
 * `RollUpQuestionStatsJob` used to recompute every question in every workspace
 * each run. It now recomputes only what changed since the previous run, and that
 * needs three facts the stat tables cannot hold:
 *
 *  - `last_started_at` — the watermark. The moment the previous run STARTED
 *    (not finished), so an answer written while it walked is still newer than it.
 *  - `min_sample` — the floor that run used. `wrong_pct` is derived from it, so a
 *    floor an operator moved invalidates every row, not only the changed ones.
 *  - `full_requested_at` — a DELETION leaves no timestamp to find. The retention
 *    sweep and an erasure request delete answers, so they set this and the next
 *    run recomputes everything.
 *
 * ⚠️ A TABLE, NOT A CACHE KEY. A flushed cache would lose `full_requested_at`
 * silently, and the stats would keep counting deleted answers with no error
 * anywhere. An empty table is the safe state: no row means a full run.
 *
 * One row (id 1), platform-level: the job walks every workspace in one pass, so
 * there is one watermark. No `workspace_id`, no `BelongsToWorkspace`, no personal
 * data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_stat_rollup_state', function (Blueprint $table) {
            $table->id();
            $table->timestamp('last_started_at')->nullable();
            $table->unsignedInteger('min_sample')->nullable();
            $table->timestamp('full_requested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_stat_rollup_state');
    }
};
