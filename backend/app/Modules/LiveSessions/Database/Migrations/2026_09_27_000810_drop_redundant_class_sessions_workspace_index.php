<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `class_sessions_workspace_id_index` — covered three times over.
 *
 * `(workspace_id, teacher_profile_id, starts_at)`, `(workspace_id, status,
 * starts_at)` and `class_sessions_cohort_timeline_index`
 * `(workspace_id, course_id, cohort_id, starts_at)` each START with
 * `workspace_id` (the first two since the table was created), so
 * every read that used the single-column index can use one of them, and every
 * session written, rescheduled or cancelled was paying for a fourth copy.
 *
 * ⚠️ NO FOREIGN KEY RIDES ON `workspace_id` — `create_live_sessions_tables`
 * declares a plain `unsignedBigInteger(...)->index()`, and no later migration
 * constrains it. Were one added, MySQL needs SOME index whose first column is
 * `workspace_id`, and the three composites above satisfy it.
 *
 * Guarded by `hasIndex()` both ways (the #251 shape); `down()` recreates it
 * under its exact name.
 */
return new class extends Migration
{
    private const INDEX = 'class_sessions_workspace_id_index';

    public function up(): void
    {
        if (Schema::hasIndex('class_sessions', self::INDEX)) {
            Schema::table('class_sessions', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('class_sessions', self::INDEX)) {
            Schema::table('class_sessions', fn (Blueprint $table) => $table->index('workspace_id', self::INDEX));
        }
    }
};
