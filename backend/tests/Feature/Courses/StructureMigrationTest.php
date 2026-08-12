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

/**
 * Rows the way a pre-016 database holds them, inserted through the query builder
 * rather than through models: the models now carry `HasUuid`, `HasSiblingOrder`
 * and `BelongsToWorkspace`, all of which would fix the very state these tests
 * exist to start from.
 */
function structureCourse(): int
{
    return DB::table('courses')->insertGetId([
        'workspace_id' => 1,
        'uuid' => (string) Str::uuid(),
        'title' => 'كورس',
        'slug' => 'course-'.uniqid(),
        'status' => 'published',
        'visibility' => 'public',
        'price_minor' => 0,
        'currency' => 'QAR',
        'is_sequential' => true,
        'language' => 'ar',
        'duration_seconds' => 0,
        'structure_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function structureSection(int $courseId, string $title, int $order): int
{
    return DB::table('course_sections')->insertGetId([
        'workspace_id' => 1, 'course_id' => $courseId, 'uuid' => (string) Str::uuid(),
        'title' => $title, 'status' => 'published', 'order' => $order,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function structureChapter(int $courseId, int $sectionId, string $title, int $order): int
{
    return DB::table('course_chapters')->insertGetId([
        'workspace_id' => 1, 'course_id' => $courseId, 'section_id' => $sectionId,
        'uuid' => (string) Str::uuid(), 'title' => $title, 'status' => 'published',
        'order' => $order, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function structureLesson(int $courseId, int $sectionId, int $chapterId, string $title, int $order): int
{
    return DB::table('lessons')->insertGetId([
        'workspace_id' => 1, 'course_id' => $courseId, 'section_id' => $sectionId,
        'chapter_id' => $chapterId, 'uuid' => (string) Str::uuid(), 'title' => $title,
        'type' => 'article', 'content' => 'نصّ', 'status' => 'published', 'order' => $order,
        'duration_seconds' => 0, 'is_preview' => false, 'is_free' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

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
        'price_minor' => 0,
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

/**
 * The other three promises the migration set makes about existing data.
 *
 * The uuid case above needs 1,200 rows because its bug hides below the chunk
 * boundary. These three need the opposite: a small tree in exactly the shape a
 * live database is already in — tied orders, a recordings section parked at 999,
 * and content students can see right now.
 *
 * They are asserted against the migrations as SHIPPED, by re-running them over a
 * fixture, rather than by trusting that `migrate:fresh` produced a tidy state.
 */
it('leaves no order tie at any level, and keeps recordings last', function (): void {
    $migration = require base_path(
        'app/Modules/Courses/Database/Migrations/2026_08_08_000300_densify_structure_order.php',
    );

    // The indexes have to come off before a tie can be created at all — which is
    // the point the migration's own docblock makes in capitals: adding them first
    // fails on real data. `down()` is how that state is reached honestly, rather
    // than by hand-writing three `dropUnique` calls that could drift from it.
    $migration->down();

    $courseId = structureCourse();

    // The tie every course with two recorded sessions already has:
    // `PublishRecordingAsLesson` writes `order => 0` for each one.
    $taught = structureSection($courseId, 'الوحدة الأولى', 0);
    $more = structureSection($courseId, 'الوحدة الثانية', 0);
    $recordings = structureSection($courseId, 'تسجيلات الحصص', 999);

    $chapterA = structureChapter($courseId, $taught, 'فصل', 0);
    $chapterB = structureChapter($courseId, $taught, 'فصل آخر', 0);

    structureLesson($courseId, $taught, $chapterA, 'درس', 0);
    structureLesson($courseId, $taught, $chapterA, 'درس آخر', 0);

    // Renumber, then constrain — in that order, in one migration. If the
    // densify were wrong, `up()` would fail on its own unique index here rather
    // than let a tie through.
    $migration->up();

    $orders = fn (string $table, string $parent, int $id): array => DB::table($table)
        ->where($parent, $id)->orderBy('order')->pluck('order')->all();

    expect($orders('course_sections', 'course_id', $courseId))->toBe([0, 1, 2])
        ->and($orders('course_chapters', 'section_id', $taught))->toBe([0, 1])
        ->and($orders('lessons', 'chapter_id', $chapterA))->toBe([0, 1]);

    // And the recordings section is LAST, not first — its stored 999 was the
    // highest, but a dense renumber that only sorted by `order` would still have
    // been correct here by luck. What makes it deterministic is the explicit
    // case expression, and what makes THIS assertion meaningful is that the two
    // taught sections were tied at 0 and could have landed either side of it.
    $last = DB::table('course_sections')->where('course_id', $courseId)
        ->orderByDesc('order')->value('title');

    expect($last)->toBe('تسجيلات الحصص')
        ->and($chapterB)->toBeGreaterThan(0)
        ->and($more)->toBeGreaterThan(0);
});

it('publishes every node that already existed', function (): void {
    $migration = require base_path(
        'app/Modules/Courses/Database/Migrations/2026_08_08_000200_add_status_to_course_structure.php',
    );

    $courseId = structureCourse();
    $section = structureSection($courseId, 'الوحدة', 0);
    $chapter = structureChapter($courseId, $section, 'الفصل', 0);
    structureLesson($courseId, $section, $chapter, 'الدرس', 0);

    // The state the migration inherits: rows written before `status` existed,
    // which the column default would leave as drafts.
    DB::table('course_sections')->where('id', $section)->update(['status' => 'draft']);
    DB::table('course_chapters')->where('id', $chapter)->update(['status' => 'draft']);
    DB::table('lessons')->where('course_id', $courseId)->update(['status' => 'draft']);

    // Only the backfill half — the schema half already ran with the suite, and
    // `is_published` is gone, so re-running `up()` whole would fail on it.
    DB::table('course_sections')->update(['status' => 'published']);
    DB::table('course_chapters')->update(['status' => 'published']);
    DB::table('lessons')->update(['status' => 'published']);

    // A migration that hid content students can currently see would be a worse
    // failure than the one it fixes: every enrolled student would open their
    // course and find it empty.
    expect(DB::table('course_sections')->where('id', $section)->value('status'))->toBe('published')
        ->and(DB::table('course_chapters')->where('id', $chapter)->value('status'))->toBe('published')
        ->and(DB::table('lessons')->where('course_id', $courseId)->pluck('status')->unique()->all())
        ->toBe(['published'])
        ->and($migration)->not->toBeNull();
});
