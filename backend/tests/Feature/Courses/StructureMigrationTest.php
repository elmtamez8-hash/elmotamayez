<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The uuid backfill leaves no row behind — asserted, not asserted-in-a-comment.
 *
 * The migration's own docblock claimed "StructureMigrationTest asserts zero nulls
 * remain" while no such test existed, and under that unexamined claim sat a real
 * defect: the backfill used `chunk()`, which paginates by OFFSET, against
 * `whereNull('uuid')`, which shrinks as the callback fills the column. Page 2
 * asked for `OFFSET 500` of a set that now began at row 501, so rows 501–1000 were
 * skipped forever and the short page ended the loop.
 *
 * It failed SILENTLY in the worst way available: a unique index permits any number
 * of NULLs, so `php artisan migrate` reported success and the consequences waited
 * for production — `getRouteKeyName()` is `uuid`, so those sections and chapters
 * 404 on every route, the tree emits `uuid: null`, and `ReorderTreeNodes` keys
 * every null-uuid sibling as `""` and refuses to reorder the group at all.
 *
 * 1,200 rows, because the bug is invisible below 501 and the old code passes any
 * fixture smaller than one chunk.
 */
it('gives every row a uuid past the chunk boundary', function (): void {
    $migration = require base_path(
        'app/Modules/Courses/Database/Migrations/2026_08_08_000100_add_uuid_to_course_structure.php',
    );

    $courseId = DB::table('courses')->insertGetId([
        'workspace_id' => 1,
        'uuid' => (string) Str::uuid(),
        'title' => 'كورس',
        'slug' => 'course-'.uniqid(),
        'status' => 'published',
        'visibility' => 'public',
        'price' => 0,
        'currency' => 'QAR',
        'is_sequential' => true,
        'language' => 'ar',
        'duration_seconds' => 0,
        'structure_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Rows as they existed BEFORE this migration: no uuid at all. Two full chunks
    // plus a remainder, so the offset drift has somewhere to lose rows.
    $rows = [];

    for ($i = 1; $i <= 1_200; $i++) {
        $rows[] = [
            'workspace_id' => 1,
            'course_id' => $courseId,
            'title' => "قسم {$i}",
            'status' => 'published',
            'order' => $i,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('course_sections')->insert($rows);
    DB::table('course_sections')->whereIn('id', DB::table('course_sections')->pluck('id'))
        ->update(['uuid' => null]);

    expect(DB::table('course_sections')->whereNull('uuid')->count())->toBe(1_200);

    $migration->backfill('course_sections');

    // Zero nulls — the claim the docblock makes, now checked. With `chunk()` this
    // leaves 500 behind.
    expect(DB::table('course_sections')->whereNull('uuid')->count())->toBe(0)
        // And every one distinct: a backfill that reused a value would break the
        // unique index on the next insert rather than here.
        ->and(DB::table('course_sections')->distinct()->count('uuid'))->toBe(1_200);
});
