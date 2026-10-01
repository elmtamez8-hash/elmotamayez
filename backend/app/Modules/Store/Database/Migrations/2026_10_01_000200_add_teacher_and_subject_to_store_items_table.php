<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public store's two filters: whose product, and which subject.
 *
 * ⚠️ STORED, NOT DERIVED PER READ. A product hangs off a workspace and maybe a
 * course; «whose» and «what subject» are resolved once at save
 * (`SaveStoreItem`) so the catalogue filters on two indexed columns instead of
 * re-deriving the course's teacher for every row.
 *
 * ⚠️ AND BACKFILLED HERE, or every product saved before the deploy would vanish
 * from the public store (its guard asks `teacher_profile_id`). The course's
 * subject when it has one; the workspace's first teacher profile — its founder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_items', function (Blueprint $table): void {
            $table->foreignId('teacher_profile_id')->nullable()->after('course_id')
                ->constrained('teacher_profiles')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->after('teacher_profile_id')
                ->constrained('subjects')->nullOnDelete();

            $table->index(['teacher_profile_id', 'is_active'], 'store_items_teacher_active_idx');
            $table->index(['subject_id', 'is_active'], 'store_items_subject_active_idx');
        });

        DB::table('store_items')->orderBy('id')->each(function (object $item): void {
            $subjectId = $item->course_id === null
                ? null
                : DB::table('courses')->where('id', $item->course_id)->value('subject_id');

            // The same founder `SaveStoreItem` falls back to: a live profile only.
            $teacherId = DB::table('teacher_profiles')
                ->where('workspace_id', $item->workspace_id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');

            DB::table('store_items')->where('id', $item->id)->update([
                'subject_id' => $subjectId,
                'teacher_profile_id' => $teacherId,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('store_items', function (Blueprint $table): void {
            // Foreign keys FIRST: MySQL lets the composite index serve each FK and
            // refuses to drop an index a constraint still needs (error 1553).
            $table->dropForeign(['teacher_profile_id']);
            $table->dropForeign(['subject_id']);
            $table->dropIndex('store_items_teacher_active_idx');
            $table->dropIndex('store_items_subject_active_idx');
            $table->dropColumn(['teacher_profile_id', 'subject_id']);
        });
    }
};
