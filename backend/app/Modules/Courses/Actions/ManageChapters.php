<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\TreeDeletionGuard;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Create, rename and remove a chapter. See ManageSections for why the three
 * verbs share a class.
 */
class ManageChapters extends Action
{
    public function create(Course $course, Section $section, string $title): Chapter
    {
        return DB::transaction(function () use ($course, $section, $title): Chapter {
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
        });
    }

    public function rename(Chapter $chapter, string $title): Chapter
    {
        $chapter->update(['title' => $title]);

        return $chapter->refresh();
    }

    public function delete(Chapter $chapter): void
    {
        TreeDeletionGuard::assertChapterDeletable($chapter);

        DB::transaction(function () use ($chapter): void {
            $chapter->lessons()->delete();

            $chapter->course?->increment('structure_version');
            $chapter->delete();
        });
    }
}
