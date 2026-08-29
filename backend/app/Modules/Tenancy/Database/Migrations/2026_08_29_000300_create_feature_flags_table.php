<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T027 — a switch per feature, with an optional override per
 * workspace.
 *
 * ⚠️ `workspace_id` IS `NOT NULL` WITH `0` MEANING «THE PLATFORM DEFAULT», AND
 * THE OBVIOUS NULLABLE VERSION DOES NOT WORK. `NULL` never equals `NULL`, so a
 * unique index carrying a nullable column does not bite on the one row every
 * request reads: two platform defaults for the same key coexist happily, an
 * `updateOrCreate` matches neither and inserts a third, and the answer becomes
 * whichever row the engine returns first. This tree has now shipped that defect
 * twice — `concept_stats.lesson_id` and `unlock_rules.course_id` — and both are
 * written down in `docs/README.md` for the same reason.
 *
 * ⚠️ AND THE PRICE OF THE SENTINEL IS `(int) null === 0`. A failed uuid resolve
 * addresses the DEFAULT row, which for a feature switch means one teacher's
 * mistake turning a feature off for the entire platform. `UnlockRuleController`
 * guards its twin with `abort_if` before writing; whatever writes here must do
 * the same, and `Flags` says so above its reader.
 *
 * ⚠️ NO `BelongsToWorkspace`, RECORDED AS A DELIBERATE VIOLATION in
 * `specs/011-commerce-growth/plan.md § Complexity Tracking`. The trait fills the
 * column from the current context, which is exactly what must not happen: the
 * platform row belongs to no workspace, and a scope keyed on `workspace_id`
 * would hide it from every reader that needs it as a fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // The name the code asks for. Not an enum: a flag is created and
            // retired far faster than a migration cycle, and an unknown key is
            // answered by the default rather than by an error.
            $table->string('key', 64);

            // 0 = the platform default. See the sentinel note above.
            $table->unsignedBigInteger('workspace_id')->default(0);

            $table->boolean('enabled')->default(false);

            // What the switch does, in Arabic, for the operator reading the
            // panel. A flag whose meaning lives only in a commit message is a
            // flag nobody dares turn off.
            $table->string('description')->nullable();

            $table->timestamps();

            $table->unique(['key', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
