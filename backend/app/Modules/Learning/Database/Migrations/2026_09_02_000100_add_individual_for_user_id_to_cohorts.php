<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 023 · T002 — whose group this is, when it is one person's.
|
| A private session is a session, and 023's first clarification says every
| session lives inside a group. So a 1:1 lesson is a group with ONE seat rather
| than an exception threaded through every query and every screen — the shape
| the spec's Clarifications rejected a separate `individual_cohorts` table for.
|
| ⚠️ `NULL ≠ NULL` ON BOTH ENGINES, AND HERE THAT IS THE POINT.
| `unique(course_id, individual_for_user_id)` therefore lets every ordinary
| group — all of them NULL — coexist freely, while no student can hold two
| private groups in one course. The uniqueness IS the duplicate guard (FR-019د):
| two concurrent acceptances race into the index, one wins, and the loser reads
| its own violation instead of writing a second group. Never `count()` then
| `insert()`, which is the definition of the race, and never `lockForUpdate()`,
| a no-op on SQLite.
|
| ⚠️ THIS DOES NOT CONTRADICT the rule that cost `concept_stats.lesson_id` and
| `unlock_rules.course_id` a fix each ("a unique index carrying a nullable column
| does not bite"). That rule is about an index that was MEANT to bite on the
| common row and silently did not. Here the common row (a group) must NOT be
| bitten and the rare one (a private group) must be. Opposite intent, same
| mechanism — which is why the reason is written here rather than left to be
| rediscovered as a bug.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cohorts', function (Blueprint $table) {
            $table->unsignedBigInteger('individual_for_user_id')->nullable()->after('course_id');

            $table->unique(['course_id', 'individual_for_user_id']);
        });
    }

    public function down(): void
    {
        // The index goes first, in its own statement. MySQL discards a
        // single-column index with its column and never complains, so
        // `dropColumn()` alone READS as correct — while SQLite's native
        // `ALTER TABLE … DROP COLUMN` refuses an indexed column outright, and
        // every test in this repository runs on in-memory SQLite. Two closures
        // for the same reason `->change()` is avoided: a multi-alteration on
        // SQLite is a table rebuild.
        Schema::table('cohorts', function (Blueprint $table) {
            $table->dropUnique(['course_id', 'individual_for_user_id']);
        });

        Schema::table('cohorts', function (Blueprint $table) {
            $table->dropColumn('individual_for_user_id');
        });
    }
};
