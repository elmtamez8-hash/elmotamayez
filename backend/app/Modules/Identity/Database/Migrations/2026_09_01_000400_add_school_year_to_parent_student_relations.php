<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 022 · FR-005 — the child's year, on the guardian's side of the same fact.
 *
 * The guardian surface is the half most easily forgotten, and it is the one that
 * matters most here: a guardian may add a child with NO ACCOUNT AT ALL, so
 * `student_user_id` is nullable and this row is the only place that child's year
 * is recorded. `student_grade_level_slug` stays as the fallback, exactly as on
 * `student_profiles`.
 *
 * ⚠️ `$fillable` IN THE SAME CHANGE — `LinkGuardian` writes through `create()`,
 * and a non-fillable key is discarded in silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parent_student_relations', function (Blueprint $table): void {
            $table->string('student_school_year_slug')->nullable();
        });
    }

    /**
     * No index to drop here — unlike `student_profiles.school_year_slug`, nothing
     * queries this column; it is read row by row from a guardian's own list.
     */
    public function down(): void
    {
        Schema::table('parent_student_relations', function (Blueprint $table): void {
            $table->dropColumn('student_school_year_slug');
        });
    }
};
