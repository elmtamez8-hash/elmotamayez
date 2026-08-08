<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A counter that says "the tree you are looking at is out of date".
 *
 * The unit being edited is the TREE, not the node. Two teachers can reorder
 * without touching a single row in common and still produce a result neither
 * intended — the second save writes positions computed against a layout that no
 * longer exists. So the version sits on the course and every structural write
 * raises it.
 *
 * Not `updated_at`: its resolution is one second by default in MySQL, and it
 * moves for writes that have nothing to do with structure, so it would reject
 * edits that were never in conflict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedInteger('structure_version')->default(1)->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('structure_version');
        });
    }
};
