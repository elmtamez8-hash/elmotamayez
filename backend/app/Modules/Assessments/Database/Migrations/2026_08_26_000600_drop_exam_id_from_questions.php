<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 6, the contract half. `questions.exam_id` is gone.
|
| ⚠️ THIS IS THE «LATER DEPLOY» STEP 5b PROMISED, AND IT IS LATER BY EIGHT SPECS.
| Relaxing the column to nullable was invisible to old code; dropping it is not.
| `Exam::questions()` was a `hasMany` on this column and `GradeAttempt` opened with
| `load('exam.questions.options')` — so a queue worker still running the release
| BEFORE 008 would answer "Unknown column" on every grading and every exam page
| for as long as the rollout took. That is the whole reason the two halves are two
| deploys, and why this file could not be written on the day the first one was.
|
| Between then and now, 009 · 010 · 013 · 014 · 015 · 016 · 017 · 019 · 020 have
| all shipped. No worker anywhere is running pre-008 code, which is what makes
| this ordinary rather than an outage.
|
| The precondition is that nothing reads or writes the column, and it is met by
| measurement, not by assumption:
|
|   - `Exam::questions()` is a `belongsToMany` through `exam_items` (`T183`).
|   - `Question::$fillable` has not listed it since 008.
|   - Every surviving `exam_id` in the tree belongs to `exam_items` or to
|     `exam_attempts` — both columns are alive and neither is touched here.
|   - `DemoDataSeeder` was the last writer and writes an `ExamItem` now. That one
|     mattered separately: `SeedCommand` runs every seeder inside
|     `Model::unguarded()`, so `$fillable` never protected it, and left in place
|     this migration would have turned `migrate --seed` into "Unknown column".
|
| ⚠️ AND THE INDEX GOES FIRST, IN ITS OWN STATEMENT. `questions_exam_id_index` was
| born with the table in 004's `_000300`. MySQL drops a single-column index with
| its column and would not care — SQLite's native `ALTER TABLE … DROP COLUMN`
| REFUSES an indexed column, and the whole test suite runs on in-memory SQLite.
| So the form that reads as redundant is the only form that runs at all here.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['exam_id']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('exam_id');
        });
    }

    /*
    | ⚠️ `down()` IS EMPTY, AND THE THREE REASONS ARE THE POINT OF WRITING THEM
    | DOWN: this is a migration whose rollback CANNOT restore what was there, and
    | a reader who assumes otherwise will roll back expecting the old behaviour.
    |
    |   1. A question that now sits in two exams does not fold back into one
    |      column. `exam_items` is what made "one question, three papers"
    |      expressible; a single `exam_id` can hold one of them and silently
    |      loses the rest. There is no correct value to pick.
    |   2. A bank question in zero exams has nothing to restore. It was authored
    |      after the split, belongs to no paper by design, and the column it
    |      would get back is null — carrying no information at any point.
    |   3. The column does NOT come back `NOT NULL`. Step 5b already declined to
    |      restore that constraint for reason 2, so even a `down()` that re-added
    |      the column would leave a schema that is not the one that preceded it.
    |
    | Which is why nothing is re-added: a column restored empty is worse than an
    | absent one, because it reads as data and holds none. Rolling back past this
    | point means restoring a backup, and saying so here is cheaper than letting
    | somebody discover it from a grading screen.
    */
    public function down(): void {}
};
