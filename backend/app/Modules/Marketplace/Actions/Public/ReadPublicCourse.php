<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\CohortScheduleDirectory;
use App\Shared\Contracts\SubscriptionDirectory;
use Illuminate\Database\Eloquent\Collection;

/**
 * One anonymous course, with its published tree.
 *
 * ⚠️ `publiclyListed()` IS THE ONLY TENANT GUARD ON THIS PATH, and it is not a
 * refinement of another one. `WorkspaceScope::apply()` adds no condition when
 * `WorkspaceContext::id()` is null, which it always is without an authenticated
 * user — so a public query written without this scope returns every workspace's
 * rows, drafts included. There is nothing underneath to catch it.
 *
 * ⚠️ AND THE KEY IS `uuid`, NEVER `slug`. `courses.slug` is unique per
 * (`workspace_id`, `slug`) — inside one workspace only — so two teachers naming
 * a course «الرياضيات ٣» produce the same slug, and a public route with no
 * workspace to read cannot tell them apart. The slug is published for display
 * and is not the address.
 */
class ReadPublicCourse extends Action
{
    public function __construct(
        private readonly CohortDirectory $cohorts,
        private readonly CohortScheduleDirectory $schedules,
        private readonly SubscriptionDirectory $subscriptions,
    ) {}

    public function handle(string $uuid): ?Course
    {
        $course = Course::query()
            ->publiclyListed()
            ->where('uuid', $uuid)
            ->with([
                'subject:id,slug,name_ar,icon',
                // ⚠️ The accessor's columns, not the attribute's name. `users`
                // has no `name` — it is composed from `first_name`/`last_name`,
                // so a constrained eager load naming `name` selects a column
                // that does not exist and every screen renders a blank. Six call
                // sites shipped that way in 010.
                'creator:id,first_name,last_name',
                'creator.teacherProfile',
            ])
            ->withCount([
                // The same constraint the card uses: an unconstrained count adds
                // the teacher's drafts and their archive to a number a visitor
                // reads as «what I get», which is a draft item's existence
                // leaking as one integer.
                'lessons as lessons_count' => fn ($query) => $query->visibleToStudents(),
                'enrollments as enrolled_count',
            ])
            ->first();

        if ($course === null) {
            return null;
        }

        $course->setRelation('curriculumLessons', $this->publishedTree($course));

        return $course;
    }

    /**
     * The published tree, read through the SAME selector the enrolled student's
     * curriculum uses.
     *
     * `Lesson::visibleToStudents()` is that selector — three status conditions
     * plus `ReferenceIntegrity`, which drops an item whose exam or session has
     * been deleted. A second definition of «published» written here would agree
     * with it today and disagree the first time a status is added, and then the
     * marketplace shows a lesson the teacher has just archived, or hides one
     * they have just published.
     *
     * Ordering is by the same triplet `Enrollment::accessTo()` derives access
     * from — section, then chapter, then lesson — so the public reading order is
     * the order the course is actually taught in.
     *
     * @return Collection<int, Lesson>
     */
    private function publishedTree(Course $course): Collection
    {
        return Lesson::query()
            // The lessons are reached from a course already proven publicly
            // listed, and a guest has no workspace context for the global scope
            // to use anyway — so the bypass is explicit rather than accidental.
            ->withoutWorkspaceScope()
            ->where('lessons.course_id', $course->getKey())
            ->visibleToStudents()
            ->with(['section:id,title,order', 'chapter:id,title,order,section_id'])
            ->join('course_sections', 'course_sections.id', '=', 'lessons.section_id')
            ->join('course_chapters', 'course_chapters.id', '=', 'lessons.chapter_id')
            ->orderBy('course_sections.order')
            ->orderBy('course_chapters.order')
            ->orderBy('lessons.order')
            ->select('lessons.*')
            ->get();
    }

    /*
    | Spec 023 · US2 — the groups, merged with when they actually meet.
    |
    | ⚠️ THE SCHEDULE IS READ FROM `CohortScheduleDirectory`, NOT DERIVED HERE.
    | That contract has answered «when does this group meet» since 021 and the
    | student's own group picker reads it — a second derivation would agree with
    | it today and disagree the first time a session status is added, and then
    | the marketplace advertises a time the picker does not show. It is bulk by
    | signature for the reason a Resource runs once per row.
    |
    | ⚠️ AND A GROUP WITH NO SESSIONS YET GETS AN EMPTY LIST, never a missing
    | key — the screen says «لم تُجدول حصص بعد» rather than rendering nothing,
    | which reads as a broken section.
    |
    | @return list<array{uuid: string, name: string, description: string|null, status: string, schedule: list<string>, seats_left?: int}>
    */
    /** @return list<array<string, mixed>> */
    public function cohortsOf(Course $course): array
    {
        $cohorts = $this->cohorts->publicCohortsFor((int) $course->getKey());

        if ($cohorts === []) {
            return [];
        }

        $schedules = $this->schedules->schedulePreviewFor(array_map(
            static fn (array $cohort): int => $cohort['id'],
            $cohorts,
        ));

        return array_map(static function (array $cohort) use ($schedules): array {
            $shape = [
                'uuid' => $cohort['uuid'],
                'name' => $cohort['name'],
                'description' => $cohort['description'],
                'status' => $cohort['status'],
                'schedule' => $schedules[$cohort['id']] ?? [],
            ];

            /*
            | ⚠️ ABSENT, NOT ZERO (FR-012). A group with no declared ceiling has
            | no number of seats left: «غير محدود» is not a quantity, and a zero
            | printed there reads as «ممتلئة» — the opposite of what it means.
            */
            if ($cohort['seats_left'] !== null) {
                $shape['seats_left'] = $cohort['seats_left'];
            }

            return $shape;
        }, $cohorts);
    }

    /**
     * Whether the private-subscription invitation may be shown (FR-003).
     *
     * ⚠️ THE SERVER ANSWERS THIS OR NOBODY CAN. The invitation is only honest
     * when the teacher has both declared hours and a priced one-to-one plan —
     * and plans live behind `auth:sanctum` while this page is anonymous and
     * server-rendered. Left to the browser it becomes either a button that is
     * pressed and refused with «هذه الباقة غير متاحة», or FR-002's two-spellings
     * defect reintroduced one requirement below FR-002.
     *
     * ⚠️ AND IT LEAKS NOTHING. Three distinguishable states — no plan, plan
     * switched off, plan awaiting a price — collapse into this one false, which
     * is exactly the collapse `PurchaseSubscription` performs for the same
     * reason: telling them apart says which teachers have a plan waiting.
     *
     * The availability read is cheap and comes first: most courses have a plan
     * and the slots table is the smaller question.
     */
    public function privateSubscriptionAvailable(Course $course): bool
    {
        $teacherProfileId = $course->creator?->teacherProfile?->getKey();

        if ($teacherProfileId === null) {
            return false;
        }

        $hasHours = AvailabilitySlot::query()
            // The slot belongs to the teacher's workspace and the reader here is
            // nobody at all — `WorkspaceScope` adds no condition for a guest, so
            // this is declared rather than relied upon.
            ->withoutWorkspaceScope()
            ->where('teacher_profile_id', $teacherProfileId)
            ->exists();

        if (! $hasHours) {
            return false;
        }

        return $this->subscriptions->hasSellablePlanFor(
            (int) $course->getKey(),
            ClassSessionType::Individual->value,
        );
    }
}
