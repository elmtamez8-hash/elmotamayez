<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use Carbon\CarbonImmutable;

/**
 * Courses's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class CoursesPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'courses';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['authored_content'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        /*
        | ⚠️ THE PREDICATE IS THE WORKSPACE, NOT `created_by`. FR-034 promises a
        | departing teacher a copy of THEIR content, and a course built by an
        | assistant inside the teacher's own workspace is the teacher's course — the
        | `created_by` column records which pair of hands typed it, which is a
        | different question. This is the one implementor that needs
        | {@see DataSubject::$workspaceIds}, and the reason that field exists rather
        | than thirteen modules each resolving membership for themselves.
        |
        | A student is a member of no workspace at all, so the list is empty for
        | them and this module contributes nothing — which is correct, and is why
        | there is no guardian gate here to get wrong.
        */
        if ($subject->workspaceIds === []) {
            yield from ExportWalk::none(...$this->describe());

            return;
        }

        yield from ExportWalk::keyed(
            'authored_content',
            Course::query()->withoutWorkspaceScope()->whereIn('workspace_id', $subject->workspaceIds),
            fn (Course $course): array => [
                'uuid' => $course->uuid,
                'title' => $course->title,
                'slug' => $course->slug,
                'description' => $course->description,
                'status' => $course->status,
                'visibility' => $course->visibility,
                'course_type' => $course->course_type,
                'language' => $course->language,
                'grade_level' => $course->grade_level,
                'created_at' => ExportWalk::at($course->created_at),
            ],
            size: 200,
        );

        /*
        | The lessons carry the actual teaching — `content` is the authored Markdown
        | (never stored HTML), and it is what makes this an export of content rather
        | than a table of contents. Sections and chapters are represented by the
        | lesson's own position rather than as two more files: they hold no text a
        | person wrote, only an ordering, and a reader opening this archive wants
        | the material, not the tree.
        */
        yield from ExportWalk::keyed(
            'authored_content',
            Lesson::query()->withoutWorkspaceScope()->whereIn('workspace_id', $subject->workspaceIds),
            fn (Lesson $lesson): array => [
                'uuid' => $lesson->uuid,
                'title' => $lesson->title,
                'type' => $lesson->type,
                'content' => $lesson->content,
                'order' => $lesson->order,
                'status' => $lesson->status,
                'duration_seconds' => $lesson->duration_seconds,
                'created_at' => ExportWalk::at($lesson->created_at),
            ],
            // Kilobytes of Markdown per row, so the page bounds the peak — the same
            // reason `cms_authorship` uses 200 rather than the default.
            size: 200,
        );
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        /*
        | ⚠️ NOTHING HAPPENS HERE, AND AN ERASURE MUST NOT TAKE A COURSE DOWN.
        | `authored_content` declares `ErasureMode::Retain` because the rows are the
        | workspace's TEACHING MATERIAL: students are enrolled in it, have paid for
        | it, and are part-way through it. A departing teacher's right to their own
        | data is answered by the EXPORT — FR-034 hands them a copy — and what
        | happens to the material afterwards is spec 013's offboarding decision
        | (US6), taken by a person with a notice period, not by a background job.
        */
        return 0;
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
