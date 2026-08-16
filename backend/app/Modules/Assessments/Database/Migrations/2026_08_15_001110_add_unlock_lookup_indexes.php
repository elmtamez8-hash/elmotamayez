<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 11b. The two indexes the gate walks on every request.
|
| ⚠️ FR-041 MAKES THIS A HOT PATH, NOT A REPORT. The condition is checked at
| every access rather than swept once, so both of these are read on every booking
| and every join — and neither column was reachable by an index before this file.
|
| `assignments.class_session_id` shipped nullable in step 9 with no index of its
| own; "what is the homework of this session?" was a full scan of a table that
| grows with every class taught.
|
| `class_sessions` has three indexes and this column pair leads none of them, so
| "the countable session before this one, in this course" — the lookup that
| decides what «previous» even means — could not use any of them. The order
| matters: workspace and course are equality, `starts_at` is the range, and a
| range column anywhere but last truncates the index at that point.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->index('class_session_id', 'assignments_class_session_index');
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->index(['workspace_id', 'course_id', 'starts_at'], 'class_sessions_course_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex('assignments_class_session_index');
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex('class_sessions_course_timeline_index');
        });
    }
};
