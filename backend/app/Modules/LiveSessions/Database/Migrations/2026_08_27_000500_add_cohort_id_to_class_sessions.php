<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 021 · T045 — a session belongs to a group (FR-025).
|
| ⚠️ NULLABLE, AND NOT ONE ROW IS BACKFILLED. Q3 forbids automatic assignment:
| every session that exists today stays `null` and is HIDDEN from discovery in a
| course that has groups, until the teacher assigns it by hand (FR-025ج) — and a
| hiding the owner of the timetable does not know about is a silent loss, which
| is why `GET /manage/courses/{course}/unassigned-sessions` ships beside it.
| A course with no groups at all keeps `null` for ever and behaves exactly as
| today (FR-036).
|
| ⚠️ THE INDEX REPLACES `class_sessions_course_timeline_index` RATHER THAN
| JOINING IT. That index exists for one lookup — "the countable session before
| this one, in this course" — and R6 moves that lookup's predicate to include
| `cohort_id`, because «the previous session» is now the previous one IN THE
| STUDENT'S OWN GROUP. Column order is equality-then-range: a range column
| anywhere but last truncates the index at that point, which is the reasoning the
| replaced migration wrote down.
|
| ⚠️ TWO SEPARATE `Schema::table` CLOSURES, and the drop is its own statement.
| A multi-alteration is a table rebuild on SQLite, and every test in this
| repository runs on in-memory SQLite.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('cohort_id')->nullable()->after('course_id');
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->index(
                ['workspace_id', 'course_id', 'cohort_id', 'starts_at'],
                'class_sessions_cohort_timeline_index',
            );
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex('class_sessions_course_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->index(['workspace_id', 'course_id', 'starts_at'], 'class_sessions_course_timeline_index');
        });

        // ⚠️ THE INDEX GOES FIRST, IN ITS OWN STATEMENT. MySQL discards a
        // single-column index along with its column and never complains; SQLite's
        // native `ALTER TABLE … DROP COLUMN` REFUSES an indexed column, and this
        // is the engine the suite runs on.
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex('class_sessions_cohort_timeline_index');
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('cohort_id');
        });
    }
};
