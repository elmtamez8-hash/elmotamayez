<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `courses(created_by, status, visibility)` — the public teacher page.
 *
 * `ShowPublicTeacher::coursesOf()` asks `created_by = ?` together with
 * `Course::publicListingConstraints()` (`status = 'published'`,
 * `visibility = 'public'`) — three equalities, and `courses` had no index
 * starting with ANY of them: the only composite is `(workspace_id, status)`,
 * and the page is cross-tenant by definition, so it names no workspace. Every
 * visit to a teacher's public page scanned the whole table. The same leading
 * column also serves the `teacher_profiles.user_id = courses.created_by`
 * correlation the listing's EXISTS walks.
 *
 * Named by hand, under MySQL's 64-character identifier limit
 * (`SchemaIdentifierLengthTest`); guarded by `hasIndex()` both ways so a
 * re-run over a half-applied deploy repairs rather than fails.
 */
return new class extends Migration
{
    private const INDEX = 'courses_creator_status_visibility_index';

    public function up(): void
    {
        if (! Schema::hasIndex('courses', self::INDEX)) {
            Schema::table('courses', fn (Blueprint $table) => $table->index(['created_by', 'status', 'visibility'], self::INDEX));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('courses', self::INDEX)) {
            Schema::table('courses', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
