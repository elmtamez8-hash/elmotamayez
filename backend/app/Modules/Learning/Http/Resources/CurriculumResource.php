<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Learning\Support\CurriculumView;
use App\Modules\Learning\Support\LessonAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The course page's payload: a tree, with a state and a reason on every row.
 *
 * ⚠️ `not_visible` AND `not_enrolled` CAN NEVER APPEAR IN IT, and they are absent
 * for two different reasons. The first REMOVES the row (FR-004): that a teacher
 * has an unfinished lesson at this position is the teacher's business, and a
 * "coming soon" row invites a student to keep trying the URL. The second makes
 * the whole request a `403` before this class is reached — it means the reader
 * has no enrolment, which is not a state a course page has.
 *
 * ⚠️ AND A DROPPED LESSON CAN EMPTY ITS PARENTS. A chapter whose every item is a
 * draft renders as a heading with nothing under it, which reads to a student as
 * a broken page rather than as work in progress — so an empty chapter, and then
 * an empty section, is dropped too.
 *
 * @mixin CurriculumView
 */
class CurriculumResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CurriculumView $view */
        $view = $this->resource;

        $enrollment = $view->enrollment;
        $course = $enrollment->course;

        $sections = $this->tree($view);

        return [
            'course' => [
                'uuid' => $course->uuid,
                'title' => $course->title,
                // ⚠️ THE SPELLING OF `PublicCourseCardResource:31`, CHARACTER FOR
                // CHARACTER. Two spellings of one URL diverge at the first change
                // to the storage disk, and the half nobody opened is the half
                // that breaks.
                'cover_url' => $course->cover_path === null ? null : asset('storage/'.$course->cover_path),
                // The workspace IS the teacher (constitution), and this is the
                // spelling `EnrollmentResource` already uses.
                'teacher_name' => $enrollment->workspace?->name,
                'is_sequential' => (bool) $course->is_sequential,
                'course_type' => $course->course_type,
                'progress_pct' => $enrollment->progress_pct,
                'completed_count' => $view->completedCount,
                'countable_count' => $view->countableCount,
                'resume_lesson_uuid' => $this->resumeUuid($view),
            ],
            /*
            | ⚠️ A PLACEHOLDER, AND DELIBERATELY NOT A STUB DIRECTORY (US3 · T063).
            |
            | Groups do not exist yet — there is no table, no membership and no
            | migration in this phase. Sending `required: false` is therefore the
            | TRUE answer for every course today, not a lie waiting to be fixed:
            | no course requires a group, so no student is missing one.
            |
            | It is present rather than absent so the client is written against
            | the shape once. When T063 lands it fills these four fields from
            | `CohortDirectory` — and `joinable_exists` is the safety valve
            | (FR-028ب): a course that requires a group while none is joinable
            | must open completely, because a condition no action can satisfy is a
            | permanent lock on content somebody paid for.
            */
            'cohort_gate' => [
                'required' => false,
                'satisfied' => true,
                'joinable_exists' => false,
                'message' => null,
            ],
            'sections' => $sections,
        ];
    }

    /**
     * The flat ordered list, nested back into sections and chapters.
     *
     * The list is already in `(section.order, chapter.order, lesson.order)`, so
     * this is one pass and no sorting: the parents are grouped by id and their
     * order is the order they were first met in.
     *
     * @return list<array<string, mixed>>
     */
    private function tree(CurriculumView $view): array
    {
        /** @var array<int, array<string, mixed>> $sections */
        $sections = [];

        foreach ($view->lessons as $lesson) {
            $access = $view->access[(int) $lesson->getKey()] ?? null;

            // FR-004: the row is removed, not shown closed.
            if ($access === null || $access->code === LessonAccess::NOT_VISIBLE) {
                continue;
            }

            $section = $lesson->section;
            $chapter = $lesson->chapter;

            if ($section === null || $chapter === null) {
                continue;
            }

            $sectionId = (int) $section->getKey();
            $chapterId = (int) $chapter->getKey();

            $sections[$sectionId] ??= [
                'uuid' => $section->uuid,
                'title' => $section->title,
                'order' => $section->order,
                'chapters' => [],
            ];

            $sections[$sectionId]['chapters'][$chapterId] ??= [
                'uuid' => $chapter->uuid,
                'title' => $chapter->title,
                'order' => $chapter->order,
                'lessons' => [],
            ];

            $sections[$sectionId]['chapters'][$chapterId]['lessons'][] = $this->row($lesson, $access, $view);
        }

        return array_values(array_map(
            static function (array $section): array {
                $section['chapters'] = array_values($section['chapters']);

                return $section;
            },
            $sections,
        ));
    }

    /**
     * One item.
     *
     * `family` and `asset_kind` travel because the row has to know what it is
     * before it may offer an action that depends on it — the rule 016 wrote down
     * after a course overview linked every item to a video-upload screen. Nothing
     * restates the mapping in TypeScript.
     *
     * @return array<string, mixed>
     */
    private function row(Lesson $lesson, LessonAccess $access, CurriculumView $view): array
    {
        $type = LessonType::from($lesson->type);
        $completed = isset($view->completedIds[(int) $lesson->getKey()]);

        return [
            'uuid' => $lesson->uuid,
            'title' => $lesson->title,
            'type' => $type->value,
            'type_label' => $type->label(),
            'family' => LessonTypeRegistry::family($type),
            'asset_kind' => LessonTypeRegistry::assetKind($type)?->value,
            'is_completable' => LessonTypeRegistry::isCompletable($type),
            'duration_seconds' => $lesson->duration_seconds,
            /*
            | ⚠️ COMPLETION OUTRANKS THE GATE, and the case is real rather than
            | theoretical: a teacher who reorders the tree can put a finished item
            | behind an unfinished one, and `accessTo()` would then refuse a
            | lesson the student has already done. Rendering that as «مقفول» tells
            | them to go and finish something they finished last week.
            */
            'state' => match (true) {
                $completed => 'completed',
                $access->allowed => 'open',
                default => 'locked',
            },
            /*
            | The sentence the student reads and the code a screen switches on —
            | asserting on Arabic prose would mean the wording can never change.
            |
            | ⚠️ COMPLETION DOES NOT SUPPRESS THE LOCK, ONLY THE STATE. It reads
            | as though it should: a finished item is finished. But `accessTo()`
            | never asks whether the TARGET is complete, so a finished lesson on
            | an expired enrolment is refused with `inactive`, and a reorder can
            | put one behind an unfinished item and refuse it with `sequence`.
            | Nulling the lock there renders «مكتمل» as a link, the student taps
            | it, and the door refuses — one answer on the screen and another
            | behind the button, produced by the very screen built to end that.
            */
            'lock' => $access->allowed ? null : [
                'code' => $access->code,
                'message' => $access->message,
                'blocked_by_title' => $access->blockedByTitle,
            ],
        ];
    }

    /**
     * «تابعْ من هنا» — the first open item that is not finished (FR-010).
     *
     * Null when there is none, which covers both ends: a finished course and a
     * course whose very first item is locked. A screen that defaulted to the
     * first lesson instead would offer a student a button that answers 403.
     */
    private function resumeUuid(CurriculumView $view): ?string
    {
        foreach ($view->lessons as $lesson) {
            $id = (int) $lesson->getKey();
            $access = $view->access[$id] ?? null;

            if ($access === null || ! $access->allowed || isset($view->completedIds[$id])) {
                continue;
            }

            if (! LessonTypeRegistry::isCompletable(LessonType::from($lesson->type))) {
                continue;
            }

            return $lesson->uuid;
        }

        return null;
    }
}
