<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One thread per cohort (021 · FR-046).
 *
 * ⚠️ `unique(cohort_id)` ON A NULLABLE COLUMN IS THE GUARD HERE AND NOT A BUG,
 * and it is worth saying because this repository records the opposite mistake
 * four times over. NULL never equals NULL — which is exactly what is wanted:
 * every private, session and lesson row carries NULL and coexists freely, while
 * two cohort rows for one group collide. `ResolveCohortConversation` is a
 * find-then-insert and therefore a race by construction; the index is what
 * catches it, and without one the thread splits in two for ever with nothing
 * that throws. Same shape as `class_session_id` and `lesson_id` beside it.
 *
 * Two closures rather than one, and `down()` drops the index in its own statement
 * before the column: SQLite's native `ALTER TABLE … DROP COLUMN` refuses an
 * indexed column, and every test in this repository runs on in-memory SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('cohort_id')->nullable()->after('lesson_id');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->unique('cohort_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique(['cohort_id']);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('cohort_id');
        });
    }
};
