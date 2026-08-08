<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\SiblingOrderRetry;
use App\Modules\Courses\Support\TreeDeletionGuard;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Create, rename and remove a chapter. See ManageSections for why the three
 * verbs share a class.
 */
class ManageChapters extends Action
{
    public function __construct(
        // Deleting a chapter deletes its items, and an item's deletion has rules
        // — attachments swept through the provider, the course duration
        // recomputed. Reimplementing them here is how the two paths drifted.
        private readonly ManageLessons $lessons,
    ) {}

    public function create(Course $course, Section $section, string $title): Chapter
    {
        return SiblingOrderRetry::around(fn (): Chapter => DB::transaction(function () use ($course, $section, $title): Chapter {
            $chapter = new Chapter([
                'workspace_id' => $course->workspace_id,
                'course_id' => $course->getKey(),
                // Derived from the parent, never taken from the payload. A
                // chapter that names a section other than the one it was created
                // inside is how a lesson ends up with a section from one branch
                // and a chapter from another.
                'section_id' => $section->getKey(),
                'title' => $title,
                'status' => ContentStatus::Draft,
            ]);
            $chapter->save();

            $course->increment('structure_version');

            return $chapter;
        }));
    }

    public function rename(Chapter $chapter, string $title): Chapter
    {
        $chapter->update(['title' => $title]);

        return $chapter->refresh();
    }

    public function delete(Chapter $chapter): void
    {
        TreeDeletionGuard::assertChapterDeletable($chapter);

        // Each child goes through `ManageLessons::delete`, not `lessons()->delete()`.
        //
        // The bulk form is a query-builder delete: it retrieves no models, fires
        // no events, and sweeps no attachments — so the rows vanished while their
        // BYTES stayed on disk with nothing able to list or remove them, their
        // caption rows survived pointing at a deleted lesson, and any live
        // playback grant was never revoked (`PlaybackGuard` checks the grant and
        // the asset, never the lesson). `FR-038ب` names this exact form as
        // forbidden, and `ManageLessons::delete` has a comment explaining why —
        // this path simply did not use it. `TreeDeletionGuard` asks about
        // `role = primary` alone, so a lesson carrying only worksheets passes and
        // reaches it.
        foreach ($chapter->lessons as $lesson) {
            $this->lessons->delete($lesson);
        }

        DB::transaction(function () use ($chapter): void {
            $chapter->course?->increment('structure_version');
            $chapter->delete();
        });
    }
}
