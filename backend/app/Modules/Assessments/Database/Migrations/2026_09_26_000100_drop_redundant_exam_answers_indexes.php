<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two single-column indexes on `exam_answers` that another index already covers.
 *
 * Each is a strict LEADING PREFIX of a wider index on the same table (measured
 * on production 2026-09-26), so every read that used it can use the wider one,
 * and every write was paying to maintain a copy:
 *
 * - `exam_answers_attempt_id_index`   ⊂ `exam_answers_attempt_id_question_id_unique`
 * - `exam_answers_workspace_id_index` ⊂ `exam_answers_queue_index` / `exam_answers_rollup_index`
 *
 * ⚠️ NO FOREIGN KEY RIDES ON EITHER COLUMN — the table was created with plain
 * `unsignedBigInteger` columns and no later migration constrains them — so
 * MySQL has no constraint that needs one of these indexes to exist, and SQLite
 * has none either. Were one added later, the covering index satisfies it.
 *
 * Guarded by `hasIndex()` both ways, so a database that never had them (or has
 * already lost them) migrates cleanly; `down()` recreates them under their
 * exact production names.
 */
return new class extends Migration
{
    /** @var array<string, string> index name => column */
    private const INDEXES = [
        'exam_answers_attempt_id_index' => 'attempt_id',
        'exam_answers_workspace_id_index' => 'workspace_id',
    ];

    public function up(): void
    {
        foreach (array_keys(self::INDEXES) as $index) {
            if (Schema::hasIndex('exam_answers', $index)) {
                Schema::table('exam_answers', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $index => $column) {
            if (! Schema::hasIndex('exam_answers', $index)) {
                Schema::table('exam_answers', fn (Blueprint $table) => $table->index($column, $index));
            }
        }
    }
};
