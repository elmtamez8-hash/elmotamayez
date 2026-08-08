<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Exceptions\ContentLockedException;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Refuses the two deletions that destroy something the teacher cannot get back.
 *
 * **Progress.** A `lesson_progress` row records that a student did the work. It
 * points at the lesson, so deleting the lesson either orphans the row or takes
 * the record of their work with it — and their percentage is computed from
 * those rows. Archiving removes the lesson from the course and leaves the
 * history intact, which is what "I don't teach this any more" actually means.
 *
 * **The item's own file.** Deleting an asset is gated behind two-factor
 * authentication (004) precisely because it destroys a teacher's own work
 * irreversibly. A lesson delete that took its video down by cascade would be a
 * back door onto that decision, so the asset has to go first, through its own
 * guarded route.
 *
 * **Attachments are deliberately NOT covered, and the line is here on purpose**
 * (FR-038ب). This asks about `role = primary` only; `ManageLessons::delete` then
 * sweeps the attachments through `DeleteMediaAsset`, bytes and live grants
 * included. A primary asset IS the item — remove it and what is left is an empty
 * lesson with a title, so refusing protects something unrecoverable. An
 * attachment is a file BESIDE it, and an item carrying three worksheets would
 * otherwise need three two-factor confirmations before it could be deleted at
 * all, which teaches the teacher to click past the prompt rather than read it.
 *
 * The same rules apply at every level: deleting a section that contains such a
 * lesson is the same act with more rows.
 */
final class TreeDeletionGuard
{
    public static function assertLessonDeletable(Lesson $lesson): void
    {
        self::assertLessonsDeletable(new Collection([$lesson->getKey()]));
    }

    public static function assertChapterDeletable(Chapter $chapter): void
    {
        self::assertLessonsDeletable(
            Lesson::query()->where('chapter_id', $chapter->getKey())->pluck('id'),
        );
    }

    public static function assertSectionDeletable(Section $section): void
    {
        self::assertLessonsDeletable(
            Lesson::query()->where('section_id', $section->getKey())->pluck('id'),
        );
    }

    /** @param  Collection<int, mixed>  $lessonIds */
    private static function assertLessonsDeletable(Collection $lessonIds): void
    {
        if ($lessonIds->isEmpty()) {
            return;
        }

        self::assertNoProgress($lessonIds);
        self::assertNoPrimaryAsset($lessonIds);
    }

    /** @param  Collection<int, mixed>  $lessonIds */
    private static function assertNoProgress(Collection $lessonIds): void
    {
        // A direct table query: lesson_progress belongs to Learning, and reading
        // one column of it to answer "has anyone done this" is cheaper and
        // clearer than pulling a model relation across the module boundary.
        $exists = DB::table('lesson_progress')->whereIn('lesson_id', $lessonIds)->exists();

        if ($exists) {
            throw new ContentLockedException(
                'لا يمكن حذف محتوى سجّل عليه طلاب تقدّماً. أرشِفه بدل حذفه — يختفي عنهم ويبقى تقدّمهم سليماً.',
            );
        }
    }

    /** @param  Collection<int, mixed>  $lessonIds */
    private static function assertNoPrimaryAsset(Collection $lessonIds): void
    {
        $exists = MediaAsset::query()
            ->where('owner_type', Lesson::class)
            ->whereIn('owner_id', $lessonIds)
            ->where('role', MediaRole::Primary)
            ->exists();

        if ($exists) {
            throw new ContentLockedException(
                'هذا المحتوى يحمل ملفاً مرفوعاً. احذف الملف أولاً من صفحة الدرس — حذفه يتطلّب تحقّقاً بخطوتين — أو أرشِف المحتوى.',
            );
        }
    }
}
