<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 022 · FR-005 — the individual year this student is in.
 *
 * ⚠️ `grade_level_slug` STAYS AND IS NOT BACKFILLED FROM THIS. The old column
 * carries a BROAD STAGE, and «secondary» does not say which year — writing
 * `year-10` for a student who never said it is inventing data. So the old column
 * is the fallback for everybody who registered before years existed, the new one
 * is what every new registration writes, and the stage is DERIVED from whichever
 * is present (`SchoolYear::stageFor`). Two stored answers to one question is the
 * shape FR-001ج forbids.
 *
 * ⚠️ `nullable`, though the form requires it: no source can answer the question
 * for an account that already exists.
 *
 * ⚠️ AND THE COLUMN GOES IN `$fillable` IN THE SAME CHANGE. Spec 013 shipped
 * three columns on THIS EXACT TABLE that mass assignment discarded in silence —
 * a 201 and three nulls, invisible because the assertions read the response echo
 * rather than the stored row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('school_year_slug')->nullable()->index();
        });
    }

    /**
     * ⚠️ THE INDEX IS DROPPED FIRST, IN ITS OWN STATEMENT, AND ONLY SQLITE WILL
     * TELL YOU. MySQL discards a single-column index along with its column and
     * never complains; SQLite's native `ALTER TABLE … DROP COLUMN` REFUSES an
     * indexed column — and every test in this repository runs on SQLite.
     */
    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropIndex(['school_year_slug']);
        });

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn('school_year_slug');
        });
    }
};
