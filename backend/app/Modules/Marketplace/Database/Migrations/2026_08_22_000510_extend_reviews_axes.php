<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The student's review of their teacher gains three axes and a period
 * (010 · FR-031 · FR-032).
 *
 * ⚠️ THE THREE AXES ARE NULLABLE, AND THE REASON DIFFERS BY ENGINE. `NOT NULL`
 * with no default is REFUSED by SQLite on a populated table and ACCEPTED by MySQL,
 * which fills **zero** — outside the 1–5 range — so every review written before
 * today would read as three zeroes. `rating` is left exactly as it was and the
 * axes are averaged over the ones actually written, which is what keeps
 * `average_rating` and `TrustScoreCalculator` untouched. That is `SC-011`.
 *
 * ⚠️ `period_start` IS THE OPPOSITE CASE AND IS `NOT NULL` WITH A SENTINEL
 * DEFAULT. It joins a unique index one migration later, and NULL never equals
 * NULL — a nullable discriminator makes FR-032 vacuous for any row that misses a
 * value, which is the `concept_stats.lesson_id` defect this repository has now
 * recorded three times. A defaulted `NOT NULL` add is accepted by both engines on
 * a populated table, so no `->change()` and no table rebuild. The sentinel is
 * replaced with each row's real period in the next migration, before the index
 * exists; if a later insert ever forgets the column, two such rows COLLIDE rather
 * than passing silently, which is the loud direction.
 */
return new class extends Migration
{
    private const SENTINEL = '1970-01-01';

    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('punctuality')->nullable()->after('rating');
            $table->unsignedTinyInteger('clarity')->nullable()->after('punctuality');
            $table->unsignedTinyInteger('engagement')->nullable()->after('clarity');
            $table->date('period_start')->default(self::SENTINEL)->after('engagement');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['punctuality', 'clarity', 'engagement', 'period_start']);
        });
    }
};
