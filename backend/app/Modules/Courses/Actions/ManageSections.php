<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\TreeDeletionGuard;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Create, rename and remove a section.
 *
 * Three verbs in one class because they share one invariant they must not
 * disagree about: every structural write raises `structure_version`, which is
 * what makes a concurrent editor's stale layout detectable. Split across three
 * files, the third one to be written is the one that forgets.
 *
 * Position is not an argument here. It is assigned by HasSiblingOrder on create
 * and rewritten only by ReorderTreeNodes, which receives the whole sibling list
 * — so a duplicate order cannot be expressed by any caller.
 */
class ManageSections extends Action
{
    public function create(Course $course, string $title): Section
    {
        return DB::transaction(function () use ($course, $title): Section {
            $section = new Section([
                'workspace_id' => $course->workspace_id,
                'course_id' => $course->getKey(),
                'title' => $title,
                // Stated, not left to the column default. The Action is where
                // "a new node is a draft" is decided (FR-024), and a model that
                // has just been saved does not carry a default it never set —
                // the resource would serialise a null status.
                'status' => ContentStatus::Draft,
            ]);
            $section->save();

            $course->increment('structure_version');

            return $section;
        });
    }

    public function rename(Section $section, string $title): Section
    {
        $section->update(['title' => $title]);

        return $section->refresh();
    }

    public function delete(Section $section): void
    {
        TreeDeletionGuard::assertSectionDeletable($section);

        DB::transaction(function () use ($section): void {
            // Children go explicitly rather than by database cascade: there is no
            // foreign key here to cascade from, and a section removed while its
            // chapters survive leaves rows nothing can reach or clean up.
            $section->chapters()->each(function ($chapter): void {
                $chapter->lessons()->delete();
                $chapter->delete();
            });

            $section->course?->increment('structure_version');
            $section->delete();
        });
    }
}
