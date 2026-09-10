<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Shared\Database\TranslatableColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Give every existing course a subject, and stop the column being nullable.
 *
 * ⚠️ `courses.subject_id` ARRIVED WITH 007's PRICING MIGRATION AND NO WRITER EVER
 * SET IT. It was fillable from the day it landed, so it read as done — and no
 * request, no Action, no seeder and no screen ever put a value in it. Measured on
 * a development database on 2026-08-27: **77 of 77 courses carried NULL**, while
 * the platform held nine defined subjects. Everything downstream that groups by
 * subject was therefore grouping nothing: the marketplace's own facet, and the
 * homework and practice filters added the same day, which rendered no control at
 * all because the list they were built from was empty.
 *
 * ⚠️ THE BACKFILL USES A NEUTRAL «عامّ» RATHER THAN GUESSING. Nothing in a row
 * says what it teaches — the titles are a mix of demo text and English — and a
 * guessed subject is a wrong fact written into a course the teacher can no longer
 * tell was never classified. «عامّ» is visibly a placeholder, which is what makes
 * it correctable; «الرياضيات» on a chemistry course is not.
 *
 * ⚠️ AND THE COLUMN IS **LEFT NULLABLE**. Every write path now demands a subject
 * — `CreateCourseRequest`, `UpdateCourseRequest`, `SubjectResolver`, the factory
 * and both seeders — so the guard is where the repository puts guards, in the
 * Action rather than only in the schema. `->change()` on SQLite is a full table
 * REBUILD, which this repository has refused twice in writing, and a NOT NULL
 * constraint would buy nothing the resolver does not already refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('courses', 'subject_id')) {
            return;
        }

        /*
         | ⚠️ THE PLACEHOLDER SUBJECT IS CREATED ONLY IF A COURSE NEEDS IT. Written
         | the obvious way — `firstOrCreate` and then the update — this invents a
         | row of reference data on every database it touches, including the fresh
         | one every test run builds. That is not merely untidy: `WorkspaceIsolationTest`
         | counts the taxonomy to prove it is NOT workspace-scoped, and a subject
         | conjured by a migration made «five» into «six». A backfill that changes
         | a database with nothing to backfill is a backfill with a side effect.
         |
         | `withoutGlobalScopes`, because a migration runs with no workspace
         | context and the scope would otherwise silently skip every row.
         */
        $unclassified = Course::query()->withoutGlobalScopes()->whereNull('subject_id');

        if (! $unclassified->exists()) {
            return;
        }

        /*
         | ⚠️ `DB::table()`, NOT `Subject::firstOrCreate()`. A migration speaks the
         | schema of ITS OWN DATE and a model speaks today's: spec 055 turned
         | `name_ar` into a translatable `name`, and the model then discarded the
         | key this table still has — in SILENCE, mass assignment's own rule — so
         | the insert would arrive with no name and fail NOT NULL. `uuid` is
         | passed explicitly because `HasUuid` is not here to supply it.
         */
        $generalId = DB::table('subjects')->where('slug', 'general')->value('id');

        if ($generalId === null) {
            $generalId = DB::table('subjects')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'slug' => 'general',
                // Whichever name column this database has — see the helper.
                ...TranslatableColumns::forWrite('subjects', 'name_ar', 'name', 'عامّ'),
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $unclassified->update(['subject_id' => $generalId]);
    }

    /**
     * Nothing to undo: the rows were null because nobody had ever set them, and
     * putting them back would be restoring the defect.
     */
    public function down(): void
    {
        //
    }
};
