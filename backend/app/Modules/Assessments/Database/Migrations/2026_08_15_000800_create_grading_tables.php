<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 8. What a person needs to mark an essay, and what is kept of the marking.
|
| ⚠️ THERE IS NO UNIQUE CONSTRAINT GUARDING AGAINST TWO GRADERS, and its absence
| is deliberate. The obvious guard — `unique(answer_id, rubric_criterion_id,
| revision_of)` — carries TWO nullable columns: an essay marked without a rubric
| writes NULL into both, and NULL never collides with NULL. Two graders would
| both insert, both succeed, and the answer would carry double marks — in exactly
| the case the index was added for. The claim lives on `exam_answers` instead
| (`Answer::claimForGrading()`), where `graded_at IS NULL` is a real predicate
| over a real row. `grading_records` is append-only and its `created_at` is never
| null, so no claim could be written against it at all.
|
| ⚠️ `max_points` AND `points` ARE DECIMAL, AND SO IS `exam_answers.points` AS OF
| THIS FILE. A rubric of "المحتوى ٢٫٥ · اللغة ٢٫٥" passes the `SUM ≤ 5` check on
| the way in and then loses its halves on the way out, because the column the
| total lands in was `unsignedSmallInteger`: the truncation happens AFTER every
| validation, silently, and the student's total is short by the number of
| criteria the teacher wrote. Signed, because unsigned arithmetic on a decimal
| raises ERROR 1690 on MySQL and nothing at all on the SQLite the suite runs on —
| the same asymmetry that cost the credit ledger a CAST in 006.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('question_id');
            $table->string('label');
            $table->decimal('max_points', 5, 2);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            // Read once per question on the grading screen, and again by
            // `SaveRubric` to sum what is already there.
            $table->index(['question_id', 'order']);
        });

        Schema::create('grading_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('answer_id');
            // Null is "the essay as a whole" — a teacher who wrote no rubric
            // still awards marks, and inventing a criterion row to hold them
            // would put words in their mouth on the analytics screen.
            $table->unsignedBigInteger('rubric_criterion_id')->nullable();
            $table->decimal('points', 5, 2)->default(0);
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('graded_by');
            // Which record this one supersedes, and why. FR-032 makes the reason
            // mandatory on a revision: a changed grade with no stated cause is
            // the one a student appeals and nobody can answer.
            $table->unsignedBigInteger('revision_of')->nullable();
            $table->string('revision_reason')->nullable();
            $table->unsignedInteger('grading_version')->default(0);
            // No `updated_at`: the table is append-only. A corrected grade is a
            // NEW row pointing at the old one, which is what makes the history
            // readable at all — an UPDATE would erase the thing being audited.
            $table->timestamp('created_at')->nullable();

            // The whole read path: "the current marks on this answer" is this
            // index with the answer's own version.
            $table->index(['answer_id', 'grading_version']);
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->decimal('points', 6, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->unsignedSmallInteger('points')->default(0)->change();
        });

        Schema::dropIfExists('grading_records');
        Schema::dropIfExists('rubric_criteria');
    }
};
