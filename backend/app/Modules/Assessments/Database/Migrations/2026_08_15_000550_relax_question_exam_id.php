<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 5b. `questions.exam_id` becomes nullable — expand now, contract later.
|
| ⚠️ THIS STEP EXISTS BECAUSE THE FIRST RUN OF THE CHAIN AGAINST REAL CODE FAILED
| WITHOUT IT. The plan had two states for this column: NOT NULL today, gone in a
| later deploy. But the code in THIS deploy stops writing it — `Question` no
| longer lists it as fillable, because inclusion in an exam is `exam_items` now —
| and a NOT NULL column nobody writes rejects every insert.
|
| So the column relaxes here and disappears in the next release. That ordering is
| the standard expand/contract, and the reason it is worth two deploys is what
| happens during the rollout of each:
|
|   - Relaxing is invisible to old code. A process still running the previous
|     release keeps writing `exam_id`, and a nullable column accepts it.
|   - Dropping is NOT invisible. `Exam::questions()` was a hasMany on this column
|     and `GradeAttempt` opened with `load('exam.questions.options')`; an old
|     worker that had not been restarted would hit "Unknown column" on every
|     grading and every exam page until the rollout finished.
|
| The transient cost of relaxing is bounded and benign: for the length of one
| rollout, a question created by new code carries no `exam_id`, so an old reader
| does not see it in that exam. A gap that closes when the deploy does, against a
| crash that lasts as long as the deploy takes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->unsignedBigInteger('exam_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately NOT restored to NOT NULL. Bank questions created after
        // this migration belong to no single exam by design, so the constraint
        // cannot be re-imposed without deleting them — and a rollback that
        // destroys rows to satisfy a constraint is worse than a schema that
        // admits it only goes one way.
    }
};
