<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 9. Homework: the thing that is due, the thing that was handed in, and the
| standing arrangement that moves the date for one student.
|
| ⚠️ THE LATE PENALTY CARRIES ITS CAP IN THE SAME ROW AS ITS RATE. Without a cap,
| ten days at 20٪ a day is −100٪ — a submission worth negative marks, which then
| drags the rest of the paper down with it (FR-046أ). The floor at zero and the
| ceiling are both enforced in the Action as well; a column that merely holds a
| number is not a rule.
|
| ⚠️ `submissions.score` IS SIGNED, and that is not a leftover. `unsignedDecimal`
| arithmetic raises ERROR 1690 on MySQL the first time an intermediate goes
| negative, and SQLite — where this suite runs — has no unsigned arithmetic to
| overflow, so no local test can ever reproduce it. The same asymmetry cost the
| credit ledger a CAST in 006 and `exam_answers.points` a widening in step 8.
|
| ⚠️ `class_session_id` SHIPS NULLABLE HERE AND IS INDEXED IN STEP 11. US7 reads
| it on every eligibility check; the column belongs to the assignment either way,
| and splitting the index out keeps that migration honest about being an index.
|
| ⚠️ `unique(assignment_id, student_user_id)` IS THE POINT OF CONTENTION. The
| nightly sweep writes a `missed` row for everyone who did not hand in; a student
| with an extension then hands in against that row. Both paths go through the
| conditional-UPDATE claim in SubmitAssignment rather than a blind insert, and
| this index is what makes the collision loud instead of a duplicate.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            // Which session this is the homework OF. Read by US7's unlock gate.
            $table->unsignedBigInteger('class_session_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('points')->default(10);
            $table->timestamp('due_at')->nullable();
            // text · file · questions (FR-044).
            $table->string('submission_type', 20)->default('text');
            // accept · reject · penalty (FR-046).
            $table->string('late_policy', 20)->default('accept');
            $table->decimal('late_penalty_pct_per_day', 5, 2)->default(0);
            $table->decimal('late_penalty_cap_pct', 5, 2)->default(100);
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            // The teacher's list, and the nightly sweep's scan: both filter on
            // status and walk by deadline.
            $table->index(['workspace_id', 'status', 'due_at']);
            $table->index(['course_id', 'status']);
        });

        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('student_user_id');
            // on_time · late · missed, stamped when it happened and never
            // recomputed — a policy edited in week ten must not re-label week
            // three (FR-045).
            $table->string('state', 20)->default('missed');
            $table->longText('answer_text')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('late_by_minutes')->default(0);
            // Frozen at grading. Derived on read, a teacher softening the policy
            // at the end of term silently re-prices everything already marked.
            $table->decimal('late_penalty_applied_pct', 5, 2)->default(0);
            // This student's own deadline for this one assignment (FR-047).
            $table->timestamp('extension_until')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->unsignedBigInteger('graded_by')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'student_user_id']);
            // "What has this student got outstanding?" — the student's own list.
            $table->index(['student_user_id', 'state']);
        });

        Schema::create('accommodations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('student_user_id');
            // A percentage for exams and whole days for homework, because the
            // two are not the same quantity: an exam is minutes long and a
            // deadline is a date (FR-053 · Q8).
            $table->unsignedSmallInteger('extra_time_pct')->default(0);
            $table->unsignedSmallInteger('extended_days')->default(0);
            $table->string('reason');
            $table->unsignedBigInteger('granted_by');
            // Withdrawn rather than deleted: FR-055 asks who granted it and
            // when, and a deleted row answers neither question afterwards.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Read on every submission and every attempt start, always by this
            // pair. One standing arrangement per student per workspace.
            $table->unique(['workspace_id', 'student_user_id']);
        });

        /*
        | ⚠️ THE DURATION THE STUDENT WAS GRANTED, FROZEN AT START. `extra_time_pct`
        | has to land somewhere, and deriving it on every read means a revoked
        | accommodation retroactively re-times a paper already sat — the same
        | class of drift `attempt_items.points` exists to prevent.
        |
        | It is ADVISORY, and saying so is part of shipping it: nothing in this
        | product enforces an exam timer server-side, in 003 or here. What this
        | column does is make the number the student is shown their own.
        */
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('is_practice');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });

        Schema::dropIfExists('accommodations');
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('assignments');
    }
};
