<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T119 — where this student lives (FR-042).
 *
 * ⚠️ `nullable`, THOUGH THE FORM REQUIRES IT. Every account that already exists
 * was created before the question was asked, and no source can answer it for
 * them; a NOT NULL column would need a made-up default, which is a guess that
 * reads as data — exactly what `dob_is_estimated` exists to mark. NULL means «we
 * never asked», and the region report says so rather than filing them under a
 * region nobody chose.
 *
 * ⚠️ AND THE COLUMN GOES IN `$fillable` IN THE SAME CHANGE. Spec 013 shipped
 * THREE columns on THIS EXACT TABLE that mass assignment discarded in silence —
 * a 201 and three nulls, invisible because the assertions read the response echo
 * rather than the stored row. `RegistrationStillWorksTest` asserts the ROW.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('region_id')->nullable()->index();
        });
    }

    /**
     * ⚠️ THE INDEX IS DROPPED FIRST, IN ITS OWN STATEMENT, AND ONLY SQLITE WILL
     * TELL YOU. MySQL discards a single-column index along with its column and
     * never complains, so `dropColumn()` alone reads as correct; SQLite's native
     * `ALTER TABLE … DROP COLUMN` REFUSES an indexed column — and every test in
     * this repository runs on in-memory SQLite.
     */
    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropIndex(['region_id']);
        });

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn('region_id');
        });
    }
};
