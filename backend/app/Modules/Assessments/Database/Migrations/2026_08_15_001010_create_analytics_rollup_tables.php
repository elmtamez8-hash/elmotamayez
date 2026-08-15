<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 10b. Where the nightly rollup lands (FR-011 · FR-014).
|
| ⚠️ `concept_stats.lesson_id` IS `NOT NULL` AND ZERO MEANS "THE CONCEPT OVERALL".
| The sentinel is not a style choice. NULL never equals NULL, so a unique index
| carrying a nullable column does not bite on the row that holds it — and that
| row is exactly the one every screen reads. `upsert()` would match nothing and
| INSERT instead: a new "concept overall" row every night, thirty of them after a
| month, and the screen showing whichever came back first — a number thirty days
| old while the correct one sits in another row. FR-014 ("read from a rollup that
| is kept current") would fail with not one error in the log.
|
| ⚠️ NO `uuid` AND NO `timestamps`, DELIBERATELY. The writer is `upsert()`, which
| boots no model — so `HasUuid` would never fire and the column would be written
| empty, the same family as the `insertOrIgnore` trap in CLAUDE.md. And these
| rows are never route-bound: they are read as a list keyed by the QUESTION's
| uuid, which the reader already has. `computed_at` is stamped by the job and is
| the only time this table needs, because "when was this recomputed" is the
| question — not when the row was first created.
|
| ⚠️ `wrong_pct` IS NULLABLE AND IS NEVER ZERO FOR A SMALL SAMPLE (FR-013).
| "Zero percent got it wrong" and "we do not know yet" are different sentences,
| and showing the second as the first is how a teacher deletes a good question
| two students happened to sit.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('question_id');
            $table->unsignedInteger('attempts_count')->default(0);
            $table->unsignedInteger('wrong_count')->default(0);
            $table->decimal('wrong_pct', 5, 2)->nullable();
            $table->timestamp('computed_at');

            // One row per question, forever. The unique index IS the idempotency
            // guard the nightly rerun leans on.
            $table->unique('question_id');

            // The screen asks "which questions do they get wrong most", so the
            // sort column belongs in the index the tenant filter already uses.
            $table->index(['workspace_id', 'wrong_pct']);
        });

        Schema::create('concept_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('concept_id');
            // Zero = the concept across every lesson. See the header.
            $table->unsignedBigInteger('lesson_id')->default(0);
            $table->unsignedInteger('attempts_count')->default(0);
            $table->unsignedInteger('wrong_count')->default(0);
            $table->decimal('wrong_pct', 5, 2)->nullable();
            $table->timestamp('computed_at');

            $table->unique(['workspace_id', 'concept_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concept_stats');
        Schema::dropIfExists('question_stats');
    }
};
