<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily cap's reservation row (FR-006).
 *
 * ⚠️ THE CAP USED TO BE "COUNT TODAY'S ENTRIES, THEN DECIDE", which is the
 * definition of the race: two events in the same second both read 9 of 10 and
 * both write, landing at 11. The claim is one conditional statement instead —
 *
 *     UPDATE ... SET count = count + 1 WHERE count < :cap
 *
 * and zero affected rows IS "the cap is reached", so the award is skipped and
 * the action that triggered it still succeeds. The seat idiom, and never
 * lockForUpdate(), which is a no-op on SQLite.
 *
 * It also takes the time-range scan out of the write path entirely: the day is
 * a string key, so the hot path is one primary-key lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('award_daily_counters', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('student_user_id');

            /** The Doha calendar day, `Y-m-d` — never a UTC one (FR-019). */
            $table->string('day_key', 10);

            $table->string('action_key', 64);
            $table->unsignedSmallInteger('count')->default(0);
            $table->timestamps();

            $table->unique(
                ['student_user_id', 'day_key', 'action_key'],
                'award_daily_counters_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('award_daily_counters');
    }
};
