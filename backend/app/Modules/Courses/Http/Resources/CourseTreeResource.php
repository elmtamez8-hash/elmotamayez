<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Resources;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\ReferenceIntegrity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The AUTHOR's view of the tree: drafts included, with the reason each node is
 * hidden.
 *
 * Deliberately a separate resource and a separate route from the student tree,
 * rather than one endpoint with an `?include_drafts=1` switch. With a switch,
 * leaking an unfinished lesson to a student becomes a matter of forgetting a
 * query parameter in one caller — and what leaks is the lesson itself.
 *
 * @mixin Course
 */
class CourseTreeResource extends JsonResource
{
    /**
     * Items whose exam or session has been deleted, resolved once per response.
     *
     * @var array<int, true>
     */
    private array $missingReferences = [];

    /**
     * The resource WITH the loads it needs — the only supported way to build it.
     *
     * A Resource runs once per row, so a relation it touches that was not eager
     * loaded is an N+1 by construction. That was already true of `tree`; it became
     * a drift risk the moment a second caller appeared, because the eager loads
     * are not three lines of `with()` — they carry a column list that exists
     * because `content` is a longText this response never emits, and a second
     * caller copying two of the three would ship every article in the course into
     * memory with nothing failing.
     *
     * The second caller is the 409 body: a stale-version write answers with the
     * tree as it actually is, so the editor can redraw its map instead of guessing
     * (contract §5).
     */
    public static function for(Course $course): self
    {
        $course->load([
            'sections' => fn ($query) => $query->orderBy('order'),
            'sections.chapters' => fn ($query) => $query->orderBy('order'),
            'sections.chapters.lessons' => fn ($query) => $query
                ->select([
                    'id', 'uuid', 'course_id', 'section_id', 'chapter_id', 'title', 'type',
                    'status', 'order', 'duration_seconds', 'is_preview', 'is_free',
                    'class_session_id', 'reference_id', 'exam_gate',
                ])
                ->orderBy('order'),
        ]);

        return self::make($course);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Two queries for the whole tree, here rather than in `lesson()` where
        // they would be one pair per row.
        $this->missingReferences = ReferenceIntegrity::missingAmong(
            $this->sections->flatMap(
                fn (Section $section) => $section->chapters->flatMap->lessons,
            ),
        );

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'is_sequential' => $this->is_sequential,
            'structure_version' => $this->structure_version,
            'sections' => $this->sections->map(
                fn (Section $section): array => $this->section($section),
            )->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function section(Section $section): array
    {
        return [
            'uuid' => $section->uuid,
            'title' => $section->title,
            'order' => $section->order,
            'status' => $section->status->value,
            'status_label' => $section->status->label(),
            'chapters' => $section->chapters->map(
                fn (Chapter $chapter): array => $this->chapter($chapter, $section),
            )->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function chapter(Chapter $chapter, Section $section): array
    {
        return [
            'uuid' => $chapter->uuid,
            'title' => $chapter->title,
            'order' => $chapter->order,
            'status' => $chapter->status->value,
            'status_label' => $chapter->status->label(),
            'blocked_by' => $this->blockedBy($chapter->status, $section->status, null),
            'lessons' => $chapter->lessons->map(
                fn (Lesson $lesson): array => $this->lesson($lesson, $section, $chapter),
            )->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function lesson(Lesson $lesson, Section $section, Chapter $chapter): array
    {
        $type = LessonType::from($lesson->type);

        return [
            'uuid' => $lesson->uuid,
            'title' => $lesson->title,
            'order' => $lesson->order,
            'type' => $type->value,
            'type_label' => $type->label(),
            'status' => $lesson->status->value,
            'status_label' => $lesson->status->label(),
            'blocked_by' => $this->blockedBy($lesson->status, $section->status, $chapter->status),
            'is_completable' => LessonTypeRegistry::isCompletable($type),
            // Says out loud that this row is a recording, because two rules turn
            // on it: it is entitled by a seat rather than by enrolment, and the
            // authoring surface may not repoint it.
            'is_recording' => $lesson->class_session_id !== null,
            // Shown to the teacher and to nobody else. The student's tree drops
            // the row (FR-045); the author has to be told, because the exam they
            // deleted took a position in their course with it.
            'reference_missing' => isset($this->missingReferences[(int) $lesson->getKey()]),
            'is_preview' => $lesson->is_preview,
            'is_free' => $lesson->is_free,
            'duration_seconds' => $lesson->duration_seconds,
        ];
    }

    /**
     * Why a node is hidden, when its own state is not the reason.
     *
     * A published lesson inside a draft section is invisible, and showing the
     * teacher "published" is the answer to a question they did not ask. "Its
     * section is a draft" is a problem they can act on.
     */
    private function blockedBy(ContentStatus $own, ContentStatus $section, ?ContentStatus $chapter): ?string
    {
        if (! $own->isVisibleToStudents()) {
            return null;
        }

        if (! $section->isVisibleToStudents()) {
            return 'section';
        }

        if ($chapter !== null && ! $chapter->isVisibleToStudents()) {
            return 'chapter';
        }

        return null;
    }
}
