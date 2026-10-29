<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The server-owned exam clock (owner decision 2026-10-29).
 *
 * `draft_answers` — the answers a student has chosen so far on an open sitting.
 * Nothing kept them before submission (the paper lived in the browser), so a
 * sitting the clock closed had nothing to mark. Overwritten whole on each save
 * and read by `GradeAttempt` when the clock closes the sitting. Not
 * `exam_answers`: those are the GRADED record, written once at the claim.
 *
 * `clock_enforced` — stamped TRUE by `StartAttempt` on every sitting from now
 * on, and FALSE on every row that exists today. ⛔ Without it the first run of
 * the minute sweep would have marked every sitting ever abandoned open — weeks
 * old, with no saved answers because saving did not exist — as ZERO, all at
 * once: an attempt spent, a result notice, possibly an academic warning, for
 * each. Those sittings keep their old behaviour; the owner decides about them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table): void {
            if (! Schema::hasColumn('exam_attempts', 'draft_answers')) {
                $table->json('draft_answers')->nullable()->after('duration_minutes');
            }

            if (! Schema::hasColumn('exam_attempts', 'clock_enforced')) {
                $table->boolean('clock_enforced')->default(false)->after('duration_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table): void {
            foreach (['draft_answers', 'clock_enforced'] as $column) {
                if (Schema::hasColumn('exam_attempts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
