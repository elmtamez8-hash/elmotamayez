<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The incremental rollup's one question: which answers in this workspace changed
 * since the last run? `(workspace_id, updated_at)` answers it with a range scan
 * instead of reading every answer the workspace ever received — which is the cost
 * the incremental run exists to avoid.
 *
 * `updated_at` moves on every write that changes what the rollup counts: the
 * insert (`AnswerMarker` stamps both timestamps explicitly), a grade and a
 * revision (`claimForGrading()` / `claimForRevision()` are Eloquent builder
 * updates, which stamp `updated_at`, and the model save after them does too).
 *
 * Guarded by `hasIndex()` both ways, like `drop_redundant_exam_answers_indexes`.
 */
return new class extends Migration
{
    private const INDEX = 'exam_answers_workspace_updated_index';

    public function up(): void
    {
        if (! Schema::hasIndex('exam_answers', self::INDEX)) {
            Schema::table('exam_answers', fn (Blueprint $table) => $table->index(['workspace_id', 'updated_at'], self::INDEX));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('exam_answers', self::INDEX)) {
            Schema::table('exam_answers', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
