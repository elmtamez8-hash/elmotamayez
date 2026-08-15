<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 7e. The constraint NFR-011 has been missing since spec 003.
|
| Submitting reads `$attempt->isGraded()` in the controller and then writes a row
| per answer in the action. That is a read followed by a write — the definition of
| the race — and two taps on a flaky connection both pass the check and both
| write the full answer set.
|
| It matters more after 008 than it did before: a duplicated answer counts the
| mistake twice in the notebook and doubles the denominator of `wrong_pct`, so a
| number that decides whether a teacher deletes a question is computed over
| duplicates.
|
| ⚠️ The unique index is the SECOND half of the fix, not the whole of it. The
| first half is an atomic status claim in the action; a constraint alone turns a
| double submit into a 500 rather than into a clean refusal.
|
| Both columns are NOT NULL, so this one actually bites — unlike a unique index
| over a nullable column, where NULL never collides with NULL.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->unique(['attempt_id', 'question_id']);
            $table->unique('uuid');
            $table->index(['student_user_id', 'question_id', 'is_correct', 'created_at'], 'exam_answers_notebook_index');
            $table->index(['workspace_id', 'question_id', 'is_correct'], 'exam_answers_rollup_index');
            $table->index(['workspace_id', 'requires_grading', 'graded_at'], 'exam_answers_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropUnique(['attempt_id', 'question_id']);
            $table->dropUnique(['uuid']);
            $table->dropIndex('exam_answers_notebook_index');
            $table->dropIndex('exam_answers_rollup_index');
            $table->dropIndex('exam_answers_queue_index');
        });
    }
};
