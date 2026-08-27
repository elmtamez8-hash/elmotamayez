<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\ReferenceIntegrity;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Contracts\SessionAttendanceDirectory;
use Illuminate\Support\Facades\DB;

/**
 * "May this student open this item, and if not, why?" — in exactly one place.
 *
 * ⚠️ THIS IS AN EXTRACTION, NOT A SECOND OPINION. The body of {@see for()} is
 * `Enrollment::accessTo()` moved across unchanged, comments included, and
 * `accessTo()` is now one line that calls it. There is no re-derivation and no
 * "equivalent" rule anywhere: the whole reason this class exists is that a
 * curriculum screen has to answer the same question for two hundred rows at
 * once, and the obvious way to do that — a bulk computation written beside the
 * single-row one — is the two-spellings defect this repository has paid for in
 * `BookingEligibility`, in `ListLeaderboardScopes`, and in the recording that
 * `IssuePlaybackGrant` allowed while `accessTo()` refused.
 *
 * So {@see forTree()} is a REWRITE OF THE DATA ACCESS AND NOT OF THE DECISION:
 * it reads the same facts in bulk and then walks them through the same order of
 * conditions, in the same sequence, with the same sentences. What guards that
 * claim after today is `LessonGateParityTest`, which asserts the two agree on
 * `allowed` and on `reason` for every item of a fixture covering all six
 * refusals. If it ever fails, the bulk form is wrong — never the single one.
 */
final class LessonGate
{
    /**
     * One item, one answer (FR-043).
     *
     * "Locked" with nothing after it tells the student nothing about what to go
     * and do — and an exam gate is invisible from the locked item, because what
     * has to happen is on another page.
     *
     * Finds the immediately preceding lesson with one targeted query and a column
     * list rather than loading the rowset: `content` is a longText and this runs
     * on every lesson open.
     */
    public static function for(Enrollment $enrollment, Lesson $lesson): LessonAccess
    {
        // A lesson from another course never counts towards this enrollment,
        // preview flag or not — otherwise progress could be driven to 100%
        // with lessons the student's course does not contain.
        if ($lesson->course_id !== $enrollment->course_id) {
            return LessonAccess::deny(
                LessonAccess::NOT_ENROLLED,
                'هذا الدرس ليس من الكورس المسجَّل فيه.',
            );
        }

        // What the student is opening has to be visible in its own right, and
        // this is checked BEFORE the preview flag — a preview lesson pulled back
        // to draft is still a draft.
        //
        // `visibleToStudents` was the only place `status` was read on the item
        // being fetched, and it guards the two LIST endpoints. The three status
        // conditions further down apply to the PREVIOUS lesson, in the
        // prerequisite query. So `/learn/lessons/{lesson}`, the endpoint that
        // carries the actual body, never asked — and `HasUuid` resolves a route
        // parameter by uuid OR id, so the ids could simply be walked until one
        // landed in the student's own course. It also meant a teacher withdrawing
        // a published lesson to revise it kept serving the old body, and a
        // student could still complete an archived item — which then made it
        // undeletable through TreeDeletionGuard.
        if (! $lesson->isVisibleChain()) {
            return LessonAccess::deny(
                LessonAccess::NOT_VISIBLE,
                'هذا الدرس غير متاح حالياً.',
            );
        }

        if ($lesson->is_preview) {
            return LessonAccess::allow();
        }

        if (! $enrollment->isActive()) {
            return LessonAccess::deny(
                LessonAccess::INACTIVE,
                'تسجيلك في هذا الكورس غير نشط حالياً.',
            );
        }

        /*
         * ⚠️ A RECORDING IS ENTITLED BY THE SEAT, AND THE SEQUENCE MUST NOT ASK
         * A SECOND QUESTION IN FRONT OF IT.
         *
         * The prerequisite query below already refuses to let a recording STAND
         * in front of anything — the forever-bug that would lock a whole course
         * behind an hour the student was never in. This is the mirror image, and
         * it was missing: the recording ITSELF was gated on finishing unrelated
         * coursework, so on 2026-08-18 a student who had booked and paid for a
         * live session was told «أكمِل … أولاً» about the lesson that session had
         * just produced. Its only entrance is this endpoint, so the recording was
         * unreachable — while `IssuePlaybackGrant::mayWatch()`, the door the file
         * is actually served through, said yes.
         *
         * Two doors disagreeing is the defect; the seat is which one is right.
         * Asked through the shared directory rather than LiveSessions' models,
         * so the rule has one implementation and Constitution III holds.
         */
        if ($lesson->class_session_id !== null) {
            return app(SessionAttendanceDirectory::class)
                ->hasBookingForLesson($enrollment->student, (int) $lesson->getKey())
                ? LessonAccess::allow()
                : LessonAccess::deny(
                    LessonAccess::NO_SEAT,
                    'هذا تسجيل حصة لم تحجز فيها مقعداً.',
                );
        }

        if (! $enrollment->course->is_sequential) {
            return LessonAccess::allow();
        }

        $lessonSectionOrder = $lesson->section->order ?? 0;
        $lessonChapterOrder = $lesson->chapter->order ?? 0;

        $query = DB::table('lessons')
            ->join('course_sections', 'lessons.section_id', '=', 'course_sections.id')
            ->join('course_chapters', 'lessons.chapter_id', '=', 'course_chapters.id')
            ->where('lessons.course_id', $enrollment->course_id)
            ->where('lessons.workspace_id', $enrollment->workspace_id)
            // Only a lesson the student can actually see and finish may stand in
            // front of the next one.
            //
            // A draft is work nobody has been asked to do, and its whole chain
            // has to be published for it to be visible at all (FR-027).
            //
            // A recording is the sharper case (FR-027أ). It is entitled by a SEAT
            // in that session, not by enrolment, and since 016 it lands where the
            // teacher placed the session — mid-tree. Left as a prerequisite it
            // would lock everything after it, permanently, for every student who
            // was not in that room. That is the same forever-bug the progress
            // denominator had, arriving through the ordering instead.
            ->whereNull('lessons.class_session_id')
            ->where('lessons.status', ContentStatus::Published->value)
            ->where('course_chapters.status', ContentStatus::Published->value)
            ->where('course_sections.status', ContentStatus::Published->value)
            ->whereIn('lessons.type', LessonTypeRegistry::completableValues())
            ->where(function ($q) use ($lessonSectionOrder, $lessonChapterOrder, $lesson) {
                $q->where('course_sections.order', '<', $lessonSectionOrder)
                    ->orWhere(function ($q2) use ($lessonSectionOrder, $lessonChapterOrder) {
                        $q2->where('course_sections.order', $lessonSectionOrder)
                            ->where('course_chapters.order', '<', $lessonChapterOrder);
                    })
                    ->orWhere(function ($q3) use ($lessonSectionOrder, $lessonChapterOrder, $lesson) {
                        $q3->where('course_sections.order', $lessonSectionOrder)
                            ->where('course_chapters.order', $lessonChapterOrder)
                            ->where('lessons.order', '<', $lesson->order);
                    });
            })
            ->orderBy('course_sections.order', 'desc')
            ->orderBy('course_chapters.order', 'desc')
            ->orderBy('lessons.order', 'desc');

        // A reference item whose exam was deleted must not stand in front of
        // anything: nobody can sit an exam that is gone, so it would lock the
        // rest of the course permanently (FR-045).
        ReferenceIntegrity::apply($query);

        // More than the id now — the gate reads the previous item's type and,
        // for an exam, which of the two conditions it was given. Still a column
        // list rather than the model: `content` is a longText and this runs on
        // every lesson open.
        $row = $query
            ->select('lessons.id', 'lessons.title', 'lessons.type', 'lessons.reference_id', 'lessons.exam_gate')
            ->first();

        // If this is the first lesson, it's always accessible.
        if ($row === null) {
            return LessonAccess::allow();
        }

        $previous = (array) $row;
        $title = (string) $previous['title'];

        if ($previous['type'] === LessonType::Exam->value) {
            return self::examGateFor($enrollment, $previous, $title);
        }

        $completed = $enrollment->progress()
            ->where('lesson_id', $previous['id'])
            ->where('status', 'completed')
            ->exists();

        return $completed
            ? LessonAccess::allow()
            : LessonAccess::deny(
                LessonAccess::SEQUENCE,
                "أكمِل «{$title}» أولاً — هذا الكورس متسلسل.",
                $title,
            );
    }

    /**
     * The exam item standing in front of this one, and what it asks (FR-042).
     *
     * Read from the ATTEMPTS, not from a `lesson_progress` row. The two answer
     * different questions and can disagree: a student may have passed the exam
     * from the exam's own page before the teacher ever placed it in the tree,
     * and refusing them on the grounds that a progress row is missing would be
     * refusing them over our bookkeeping rather than over their work.
     *
     * "Attempted" means SUBMITTED. A started-and-abandoned attempt is a row that
     * exists because the student opened the page; treating it as a pass through
     * the gate would make the weaker gate no gate at all.
     *
     * @param  array<string, mixed>  $previous
     */
    private static function examGateFor(Enrollment $enrollment, array $previous, string $title): LessonAccess
    {
        $gate = ExamGate::tryFrom((string) ($previous['exam_gate'] ?? '')) ?? ExamGate::Attempt;

        // The predicate itself lives in one place, shared with the two writers of
        // the item's progress row. Three copies of "attempted" would be three
        // definitions, and the first to drift decides whether a course can be
        // finished at all.
        if (ExamGateSatisfaction::metBy((int) $previous['reference_id'], $gate, (int) $enrollment->student_user_id)) {
            return LessonAccess::allow();
        }

        return self::examRefusal($gate, $title);
    }

    /**
     * The two sentences an unmet exam gate is worded with.
     *
     * Split out of `examGateFor()` for {@see forTree()} alone, which has already
     * answered the predicate in bulk and needs only the wording. Restating either
     * sentence there would be the two-spellings defect arriving as prose: the
     * curriculum row and the lesson page would tell the same student two
     * different things about the same closed item.
     */
    private static function examRefusal(ExamGate $gate, string $title): LessonAccess
    {
        return $gate === ExamGate::Pass
            ? LessonAccess::deny(
                LessonAccess::EXAM_PASS,
                "لا يُفتح ما بعد «{$title}» حتى تجتاز الاختبار بالدرجة المطلوبة. أعِد المحاولة من صفحة الاختبار.",
                $title,
            )
            : LessonAccess::deny(
                LessonAccess::EXAM_ATTEMPT,
                "أدِّ اختبار «{$title}» وسلّم إجابتك ليُفتح ما بعده — الدرجة لا تحجبك.",
                $title,
            );
    }

    /**
     * The same decision for every item of the course, in a fixed number of
     * queries (FR-008 · FR-009 · SC-004).
     *
     * ⚠️ THE ORDER OF THE BRANCHES BELOW IS THE SEMANTICS, NOT A STYLE. It is
     * the order of {@see for()}, condition for condition, and three of the steps
     * are only correct where they stand:
     *
     * - the visibility chain is asked BEFORE the preview flag, so a preview
     *   lesson pulled back to draft is still a draft;
     * - `is_preview` is asked BEFORE the enrolment status, so a preview item
     *   opens on an expired enrolment — which is what a preview is for;
     * - a recording answers to its SEAT and returns, so the sequence never gets
     *   to ask a second question in front of it.
     *
     * Everything read here is read once for the whole tree: the ordered lessons,
     * the completed progress ids, the satisfied exam ids, and the lessons the
     * seat entitles. The single "previous countable item" query of {@see for()}
     * becomes the walk itself — the items are already in
     * `(section.order, chapter.order, lesson.order)` and the walk carries the
     * last eligible one it passed.
     *
     * @param  iterable<Lesson>|null  $lessons  the ordered tree when the caller
     *                                          already has it; loaded here if not
     * @return array<int, LessonAccess> keyed by lesson id
     */
    public static function forTree(Enrollment $enrollment, ?iterable $lessons = null): array
    {
        $enrollment->loadMissing('course');

        $items = $lessons === null
            ? array_values($enrollment->orderedLessons()->all())
            : array_values([...$lessons]);

        if ($items === []) {
            return [];
        }

        $completedIds = $enrollment->progress()
            ->where('status', 'completed')
            ->pluck('lesson_id')
            ->map(intval(...))
            ->flip()
            ->all();

        // Which items may STAND IN FRONT of another — the `whereNull` /
        // three-status / completable / ReferenceIntegrity conditions of the
        // prerequisite query in `for()`, asked once for the whole course instead
        // of once per row. `progressEligible()` is the scope that already holds
        // three of the four (type, recording, reference integrity), so it is
        // reused rather than restated; the status chain is the fourth and is
        // spelled the way `countableForProgress()` spells it.
        $eligibleIds = $enrollment->course->lessons()
            ->where('lessons.workspace_id', $enrollment->workspace_id)
            ->countableForProgress()
            ->pluck('lessons.id')
            ->map(intval(...))
            ->flip()
            ->all();

        $satisfiedExamIds = ExamGateSatisfaction::satisfiedFor(
            (int) $enrollment->student_user_id,
            self::examGatesAmong($items, $eligibleIds),
        );

        // ⚠️ ASKED ONCE, AND ONLY IF THE TREE HOLDS A RECORDING. The single-row
        // form asks the directory per lesson, which is the N+1 this method
        // exists to remove; the bulk form is on the same interface, so the two
        // still read one implementation.
        $seatLessonIds = null;

        $sequential = (bool) $enrollment->course->is_sequential;
        $active = $enrollment->isActive();

        /** @var array<int, LessonAccess> $out */
        $out = [];

        /** @var array{title: string, id: int, type: string, reference_id: int|null, exam_gate: string|null}|null $previous */
        $previous = null;

        foreach ($items as $lesson) {
            $id = (int) $lesson->getKey();

            $access = (function () use (
                $lesson, $id, $enrollment, $completedIds,
                $satisfiedExamIds, &$seatLessonIds, $sequential, $active, $previous,
            ): LessonAccess {
                if ($lesson->course_id !== $enrollment->course_id) {
                    return LessonAccess::deny(
                        LessonAccess::NOT_ENROLLED,
                        'هذا الدرس ليس من الكورس المسجَّل فيه.',
                    );
                }

                if (! $lesson->isVisibleChain()) {
                    return LessonAccess::deny(
                        LessonAccess::NOT_VISIBLE,
                        'هذا الدرس غير متاح حالياً.',
                    );
                }

                if ($lesson->is_preview) {
                    return LessonAccess::allow();
                }

                if (! $active) {
                    return LessonAccess::deny(
                        LessonAccess::INACTIVE,
                        'تسجيلك في هذا الكورس غير نشط حالياً.',
                    );
                }

                if ($lesson->class_session_id !== null) {
                    $seatLessonIds ??= array_flip(
                        app(SessionAttendanceDirectory::class)->bookedLessonIdsFor($enrollment->student),
                    );

                    return isset($seatLessonIds[$id])
                        ? LessonAccess::allow()
                        : LessonAccess::deny(
                            LessonAccess::NO_SEAT,
                            'هذا تسجيل حصة لم تحجز فيها مقعداً.',
                        );
                }

                if (! $sequential) {
                    return LessonAccess::allow();
                }

                if ($previous === null) {
                    return LessonAccess::allow();
                }

                $title = $previous['title'];

                if ($previous['type'] === LessonType::Exam->value) {
                    $gate = ExamGate::tryFrom((string) ($previous['exam_gate'] ?? '')) ?? ExamGate::Attempt;

                    return isset($satisfiedExamIds[(int) $previous['reference_id']][$gate->value])
                        ? LessonAccess::allow()
                        : self::examRefusal($gate, $title);
                }

                return isset($completedIds[$previous['id']])
                    ? LessonAccess::allow()
                    : LessonAccess::deny(
                        LessonAccess::SEQUENCE,
                        "أكمِل «{$title}» أولاً — هذا الكورس متسلسل.",
                        $title,
                    );
            })();

            $out[$id] = $access;

            // The walking equivalent of the prerequisite query: only an item that
            // could stand in front of another one becomes "previous". Updated
            // AFTER this row is answered, so an item never gates itself.
            if (isset($eligibleIds[$id])) {
                $previous = [
                    'id' => $id,
                    'title' => $lesson->title,
                    'type' => $lesson->type,
                    'reference_id' => $lesson->reference_id,
                    'exam_gate' => $lesson->exam_gate?->value,
                ];
            }
        }

        return $out;
    }

    /**
     * The exam ids that can gate something in this tree, with the gate each was
     * given — so the satisfaction predicate is asked once per (exam, gate).
     *
     * Only ELIGIBLE exam items are collected: an exam that cannot stand in front
     * of anything cannot gate anything either, and asking about it would spend a
     * query on an answer nothing reads.
     *
     * @param  list<Lesson>  $items
     * @param  array<int, int>  $eligibleIds
     * @return array<int, list<ExamGate>>
     */
    private static function examGatesAmong(array $items, array $eligibleIds): array
    {
        $out = [];

        foreach ($items as $lesson) {
            if ($lesson->type !== LessonType::Exam->value || ! isset($eligibleIds[(int) $lesson->getKey()])) {
                continue;
            }

            $examId = $lesson->reference_id;

            if ($examId === null) {
                continue;
            }

            $gate = $lesson->exam_gate ?? ExamGate::Attempt;
            $out[$examId][$gate->value] = $gate;
        }

        return array_map(array_values(...), $out);
    }
}
