<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 12. The one index the 008 chain left on the floor.
|
| ⚠️ THIS MIGRATION DECLARES NOTHING A `Schema::create` ALREADY DECLARED. Every
| table born in this spec carried its own indexes in with it — `unique(uuid)`,
| `unique(exam_id, question_id)`, `unique(assignment_id, student_user_id)`, the
| notebook, queue and rollup composites. Re-stating one of them here is not a
| harmless duplicate: MySQL answers `Duplicate key name` and the whole deploy
| stops, on a statement that reads as belt-and-braces to whoever wrote it.
|
| ⚠️ AND ONLY ONE WAS ACTUALLY MISSING, which is why this file is four lines
| rather than forty. The audit walked every hot read in the module against what
| the chain had already declared:
|
|   - the mistake notebook  → `exam_answers_notebook_index`
|   - the grading board     → `exam_answers_queue_index`
|   - the nightly rollup    → `exam_answers_rollup_index`
|   - the practice pool     → `(workspace_id, student_user_id, exam_id)` on attempts
|   - the assignment sweep  → `(workspace_id, status, due_at)`
|   - the unlock gate       → `assignments_class_session_index` (spec 008, US7)
|   - the filtered bank     → `(workspace_id, concept_id, difficulty)` · `(workspace_id, lesson_id)`
|
| The gap was the bank with NO filter on it — the screen a teacher opens first
| and the only one whose row count grows without bound. `BankSearch` applies
| `is_active` on every path, filtered or not, and sorts `id` descending. Against
| `(workspace_id)` alone that is a full scan of the teacher's questions plus a
| filesort of all of them, to show twenty.
|
| `id` is deliberately NOT the third column. InnoDB appends the primary key to
| every secondary index, so entries under one `(workspace_id, is_active)` pair
| are already in `id` order and `ORDER BY id DESC` becomes a backward index scan
| with no sort at all. Naming `id` would add a column that is already there.
|
| Not added, and the reason: `bloom_level` has six values against an `is_active`
| predicate that is always present, so an index on it saves a fraction of a scan
| this one already bounds. Add it when a bank is large enough for the Bloom
| filter to be measurably slower than the unfiltered page — not before.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->index(['workspace_id', 'is_active'], 'questions_bank_page_index');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex('questions_bank_page_index');
        });
    }
};
