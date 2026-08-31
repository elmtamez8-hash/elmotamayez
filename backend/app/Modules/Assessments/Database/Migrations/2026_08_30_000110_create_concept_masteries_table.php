<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 012 · T018 — this student has mastered this concept.
 *
 * A BRIDGE, for `adaptive_sessions`' reasons exactly.
 *
 * ⚠️ STORED AND NOT DERIVED, WHICH IS A DELIBERATE DEPARTURE from this
 * repository's standing preference for deriving. The threshold is a
 * `platform_settings` row an operator edits (FR-007), so a derived mastery means
 * raising it from three to four silently withdraws mastery from every student who
 * had already earned it — retroactively, overnight, with nothing in any log.
 *
 * ⚠️ AND THE TWO `threshold_*` COLUMNS ARE NOT DECORATION: without them nobody
 * can later read ON WHAT TEST this row was granted, which is the first question
 * asked after the first change. `threshold_correct` is `unsignedTinyInt`, so an
 * operator typing `300` into the setting produces a write MySQL rejects in strict
 * mode and SQLite silently truncates — the clamp lives in `AdaptiveSettings` at
 * READ time, because the panel is not the only writer.
 *
 * ⚠️ AND THE UNIQUE INDEX ACTUALLY BITES, because both its columns are NOT NULL.
 * A unique index carrying a nullable column does not — the lesson
 * `concept_stats.lesson_id` and `unlock_rules.course_id` each cost this very
 * module a sentinel row. It is also what makes the award idempotent: one row per
 * (student, concept) by definition, unlike `source_session_id`, which differs
 * between two sessions of the same concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concept_masteries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            $table->unsignedBigInteger('concept_id')->index();

            $table->timestamp('mastered_at');

            // The criterion this row was granted on, frozen. See above.
            $table->unsignedTinyInteger('threshold_correct');
            $table->string('threshold_difficulty', 8);

            $table->unsignedBigInteger('source_session_id')->nullable();

            $table->timestamps();

            $table->unique(['student_user_id', 'concept_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concept_masteries');
    }
};
