<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The attempt allowance becomes a CLAIM, not a count (2026-09-25).
|
| `StartAttempt::guardAttemptLimit()` counted the official attempts and then
| inserted — a read followed by a write. A double tap on «ابدأ» with
| `max_attempts = 1` put two workers through the count together, both read
| zero, and both inserted: two graded attempts at a paper allowed one.
|
| `attempt_number` is the n-th OFFICIAL sitting of this exam by this student,
| and `unique(exam_id, student_user_id, attempt_number)` is the claim — the two
| taps both compute 1 and the database lets exactly one of them have it. Never
| `lockForUpdate()`, a no-op on SQLite.
|
| ⚠️ NULLABLE ON PURPOSE. A practice attempt spends no official attempt
| (FR-026أ) and carries NULL, and NULL never collides with NULL in a unique
| index — which is here the property wanted, not the trap it usually is.
|
| ⚠️ THE BACKFILL RUNS BEFORE THE INDEX, in its own step. MySQL refuses a
| unique index over rows that already violate it, and the rows this column
| exists to prevent may already be in the table. Existing official attempts are
| numbered 1, 2, 3… per (exam, student) in id order.
|
| The index name is written by hand: Laravel's own would be
| `exam_attempts_exam_id_student_user_id_attempt_number_unique`, 57 characters —
| inside MySQL's 64, but one column away from ERROR 1059, which SQLite never
| raises.
*/
return new class extends Migration
{
    private const INDEX = 'exam_attempts_attempt_number_unique';

    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempt_number')->nullable();
        });

        $this->backfill();

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->unique(['exam_id', 'student_user_id', 'attempt_number'], self::INDEX);
        });
    }

    public function down(): void
    {
        // Index first, in its own statement: SQLite's DROP COLUMN refuses an
        // indexed column.
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropColumn('attempt_number');
        });
    }

    /**
     * Number the official attempts already in the table.
     *
     * Walked by id rather than by OFFSET: the predicate does not shrink under
     * the walk here, but `lazyById` is the form that stays right if it ever does.
     */
    private function backfill(): void
    {
        /** @var array<string, int> $seen */
        $seen = [];

        DB::table('exam_attempts')
            ->whereNotNull('exam_id')
            ->where('is_practice', false)
            ->select(['id', 'exam_id', 'student_user_id'])
            ->lazyById(1000)
            ->each(function (object $row) use (&$seen): void {
                $key = $row->exam_id.':'.$row->student_user_id;
                $seen[$key] = ($seen[$key] ?? 0) + 1;

                DB::table('exam_attempts')
                    ->where('id', $row->id)
                    ->update(['attempt_number' => $seen[$key]]);
            });
    }
};
