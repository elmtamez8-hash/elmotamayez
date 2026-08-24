<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One review per pair becomes one review per pair PER PERIOD (010 · FR-032).
 *
 * ⚠️ THE SHIPPED UNIQUE MADE FR-032 TRUE FOR FREE AND UNIMPLEMENTABLE FOREVER.
 * `unique(teacher_profile_id, student_id)` means one row for all time — so «one
 * per period» is satisfied vacuously and a second period can never be entered at
 * all. Widening it is the requirement.
 *
 * ⚠️ AND THE BACKFILL COMES BEFORE THE INDEX — the 016 ordering (densify, then
 * index). Every existing row still carries the sentinel written one migration ago;
 * indexing first would put the whole table on one value, which is harmless here
 * only by luck (the old unique already guarantees the pair is distinct) and is the
 * habit that failed a deploy on live data once already.
 *
 * `DATE(created_at)` in one statement rather than a chunked walk: both engines
 * accept it, the predicate does not shrink under the update the way a
 * `WHERE uuid IS NULL` backfill does, and this table is one row per pair.
 */
return new class extends Migration
{
    private const SENTINEL = '1970-01-01';

    public function up(): void
    {
        DB::table('reviews')
            ->where('period_start', self::SENTINEL)
            ->update(['period_start' => DB::raw('DATE(created_at)')]);

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['teacher_profile_id', 'student_id']);
            $table->unique(['teacher_profile_id', 'student_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['teacher_profile_id', 'student_id', 'period_start']);
            $table->unique(['teacher_profile_id', 'student_id']);
        });
    }
};
