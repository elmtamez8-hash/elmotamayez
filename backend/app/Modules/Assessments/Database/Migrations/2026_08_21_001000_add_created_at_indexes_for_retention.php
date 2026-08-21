<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The indexes the retention sweep walks (spec 013 · T129).
 *
 * ⚠️ NONE OF THESE THREE TABLES HAD A `created_at` INDEX AT ALL, and they are the
 * two fastest-growing tables in the product plus the snapshot table hanging off
 * one of them. `exam_answers` carries a four-column index ENDING in `created_at`,
 * which reads as covered and is not: a composite is usable only from its leading
 * column, and the sweep's predicate names no student and no question.
 *
 * `attempt_items` is the one that hides — it holds no personal column of its own,
 * only a snapshot of what a named person was asked, reachable through
 * `attempt_id`. It is swept with its parent, so it needs its own cursor.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['exam_attempts', 'exam_answers', 'attempt_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['exam_attempts', 'exam_answers', 'attempt_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['created_at']);
            });
        }
    }
};
