<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two single-column indexes in Learning that another index already covers.
 *
 * Each is a strict LEADING PREFIX of a wider index on the same table (measured
 * on production 2026-09-26), so every read that used it can use the wider one,
 * and every write was paying to maintain a copy:
 *
 * - `lesson_progress.lesson_progress_enrollment_id_index`
 *     ⊂ `lesson_progress_enrollment_id_lesson_id_unique`
 * - `enrollments.enrollments_workspace_id_index`
 *     ⊂ `enrollments_workspace_id_student_user_id_index`,
 *       `enrollments_workspace_id_course_id_student_user_id_unique`,
 *       `enrollments_workspace_status_index`
 *
 * ⚠️ NO FOREIGN KEY RIDES ON EITHER COLUMN — both tables were created with plain
 * `unsignedBigInteger` columns and no later migration constrains them — so
 * MySQL has no constraint that needs one of these indexes to exist, and SQLite
 * has none either. Were one added later, the covering index satisfies it.
 *
 * Guarded by `hasIndex()` both ways; `down()` recreates them under their exact
 * production names.
 */
return new class extends Migration
{
    /** @var list<array{string, string, string}> table, index name, column */
    private const INDEXES = [
        ['lesson_progress', 'lesson_progress_enrollment_id_index', 'enrollment_id'],
        ['enrollments', 'enrollments_workspace_id_index', 'workspace_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $index]) {
            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$table, $index, $column]) {
            if (! Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($column, $index));
            }
        }
    }
};
