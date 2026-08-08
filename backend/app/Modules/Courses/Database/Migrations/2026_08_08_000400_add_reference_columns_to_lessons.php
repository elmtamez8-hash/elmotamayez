<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a content item point at something instead of holding it.
 *
 * An exam belongs to a course today but has no place IN it, so a teacher cannot
 * put the unit test between the last lesson of one unit and the first of the
 * next. `reference_id` gives it one, and the `type` column already says what the
 * id refers to.
 *
 * One column, not a `reference_type`/`reference_id` pair: the type is already
 * recorded, and a second copy of it can disagree with the first — `type = exam`
 * beside `reference_type = Assignment` is a row nobody can interpret.
 *
 * And no foreign key. `lessons` lives in Courses; `exams` in Assessments and
 * `class_sessions` in LiveSessions. A constraint here couples the schema in a
 * direction the constitution forbids coupling the code. An item whose target is
 * gone is filtered out where the tree is read, which fails safe: a listener can
 * go unregistered, but every reader passes through the scope by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->unsignedBigInteger('reference_id')->nullable()->after('chapter_id');
            $table->string('external_url')->nullable()->after('content');

            // Answering "is this exam still placed anywhere?" when the exam is
            // deleted — a scan of every lesson row would be the alternative.
            $table->index(['workspace_id', 'type', 'reference_id'], 'lessons_reference_index');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex('lessons_reference_index');
            $table->dropColumn(['reference_id', 'external_url']);
        });
    }
};
