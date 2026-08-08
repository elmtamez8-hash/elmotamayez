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
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
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
