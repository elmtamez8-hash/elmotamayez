<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every node of the tree a publication state.
 *
 * Only sections had one, as a boolean; chapters and lessons had nothing at all.
 * That is the root of a bug live in production: `recomputeProgress()` counts
 * `$course->lessons()` with no distinction, so a half-written lesson saved into
 * a course thirty students are enrolled in drops every one of their percentages
 * the moment it is created — and enters the sequential gate as an empty row
 * standing in front of the next lesson. Draft state is what makes authoring a
 * live course possible at all; it is not a convenience.
 *
 * The column defaults to `draft` because that is what a NEW node must be
 * (FR-024). Everything that already exists is then set to `published`: a
 * migration that hides content students can currently see would be a worse
 * failure than the one it fixes.
 *
 * Table names written out rather than looped — Larastan reads these statically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->string('status')->default('draft')->after('title');
            $table->index(['workspace_id', 'status'], 'course_sections_workspace_status_index');
        });

        Schema::table('course_chapters', function (Blueprint $table): void {
            $table->string('status')->default('draft')->after('title');
            $table->index(['workspace_id', 'status'], 'course_chapters_workspace_status_index');
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('status')->default('draft')->after('title');
            $table->index(['workspace_id', 'status'], 'lessons_workspace_status_index');
        });

        DB::table('course_sections')->update(['status' => 'published']);
        DB::table('course_chapters')->update(['status' => 'published']);
        DB::table('lessons')->update(['status' => 'published']);

        // Sections already answered this question with a boolean. Carry the
        // answer across before dropping the column, so a section an operator
        // deliberately unpublished stays unpublished.
        DB::table('course_sections')->where('is_published', false)->update(['status' => 'draft']);

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropColumn('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->boolean('is_published')->default(true);
        });

        DB::table('course_sections')->where('status', '!=', 'published')->update(['is_published' => false]);

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropIndex('course_sections_workspace_status_index');
            $table->dropColumn('status');
        });

        Schema::table('course_chapters', function (Blueprint $table): void {
            $table->dropIndex('course_chapters_workspace_status_index');
            $table->dropColumn('status');
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex('lessons_workspace_status_index');
            $table->dropColumn('status');
        });
    }
};
