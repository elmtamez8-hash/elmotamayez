<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes sibling order distinct, then makes it impossible for it not to be.
 *
 * `Enrollment::canAccessLesson()` decides what a student may open from the
 * triplet (section order, chapter order, lesson order). All three columns
 * default to 0, so unless something wrote them deliberately the order of
 * siblings is undefined — and something does not: `PublishRecordingAsLesson`
 * writes `order => 0` for every recording it publishes. Any course with two
 * recorded sessions has a tie today.
 *
 * ORDER MATTERS AND IT IS THE WHOLE POINT OF THIS FILE. Adding the unique
 * indexes first fails on real data. Renumber, then constrain, in one migration —
 * split across two, an environment that ran only the second gets an error
 * instead of a fix.
 *
 * Ties are broken by `id`, which is stable and repeatable. Any other tiebreak
 * (insertion order as the driver returns it, title) gives two runs against two
 * copies of the same database two different orders for the same content.
 */
return new class extends Migration
{
    /**
     * Recordings sort last within their course.
     *
     * `PublishRecordingAsLesson` puts them in a section of their own at order
     * 999 so the taught material is not pushed down by them. Renumbering densely
     * would drop that section into the middle of the tree unless the sort is
     * told about it.
     */
    private const RECORDINGS_TITLE = 'تسجيلات الحصص';

    public function up(): void
    {
        $this->densify('course_sections', 'course_id', recordingsLast: true);
        $this->densify('course_chapters', 'section_id');
        $this->densify('lessons', 'chapter_id');

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->unique(['course_id', 'order'], 'course_sections_course_order_unique');
        });

        Schema::table('course_chapters', function (Blueprint $table): void {
            $table->unique(['section_id', 'order'], 'course_chapters_section_order_unique');
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->unique(['chapter_id', 'order'], 'lessons_chapter_order_unique');
        });
    }

    public function down(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropUnique('course_sections_course_order_unique');
        });

        Schema::table('course_chapters', function (Blueprint $table): void {
            $table->dropUnique('course_chapters_section_order_unique');
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropUnique('lessons_chapter_order_unique');
        });
    }

    /**
     * Rewrite each sibling group as 0, 1, 2, … in its current apparent order.
     */
    private function densify(string $table, string $parentColumn, bool $recordingsLast = false): void
    {
        $parents = DB::table($table)->distinct()->orderBy($parentColumn)->pluck($parentColumn);

        foreach ($parents as $parentId) {
            $query = DB::table($table)->where($parentColumn, $parentId);

            if ($recordingsLast) {
                // Not ORDER BY title: the recordings section must be last even
                // if its stored order was already the lowest in the course.
                $query->orderByRaw('case when title = ? then 1 else 0 end asc', [self::RECORDINGS_TITLE]);
            }

            $siblings = $query->orderBy('order')->orderBy('id')->pluck('id');

            foreach ($siblings as $position => $id) {
                DB::table($table)->where('id', $id)->update(['order' => $position]);
            }
        }
    }
};
