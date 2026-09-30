<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\DTOs\CourseFilterDTO;
use App\Shared\Actions\Action;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Anonymous, cross-workspace course listing.
 *
 * publiclyListed() is mandatory, not a refinement — see the trait's docblock.
 * WorkspaceScope adds no condition for a guest, so a query without it returns
 * every workspace's drafts and private courses.
 */
class ListPublicCourses extends Action
{
    /** @return LengthAwarePaginator<int, Course> */
    public function handle(CourseFilterDTO $filters): LengthAwarePaginator
    {
        $query = Course::query()
            ->publiclyListed()
            ->with([
                'creator:id,first_name,last_name',
                // Unscoped: a SIGNED-IN reader's context would hide a profile kept in
                // another workspace — a 200 with no teacher on the card.
                'creator.teacherProfile' => fn ($query) => $query->withoutWorkspaceScope(),
                'teacherProfile.user:id,first_name,last_name',
                'subject',
            ])
            ->withCount([
                // Scoped, and it was not. `withCount('lessons')` counts every row
                // — so a teacher's half-written drafts and their retired archive
                // both inflated the number a visitor is shown, and the course
                // advertised more items than it opens. `FR-062` bans a draft
                // item's fields from a public payload, and a count computed from
                // those items is the same leak arriving as one number.
                'lessons as lessons_count' => fn ($query) => $query->visibleToStudents(),
                'enrollments as enrolled_count',
            ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters->sort);

        $page = $query->paginate(perPage: $filters->perPage, page: $filters->page);

        // One answer for the page, not a query per card.
        Course::primeCreatorTeaches($page->items());

        return $page;
    }

    /** @param Builder<Course> $query */
    private function applyFilters(Builder $query, CourseFilterDTO $filters): void
    {
        // Courses carry no taxonomy of their own. Rather than duplicate the
        // subject/grade pivots onto a second table that could then disagree with
        // the teacher's, the filter reads through the author's profile: a maths
        // teacher's course is a maths course.
        $query->when($filters->subject, fn (Builder $q, string $slug) => self::throughTeacher(
            $q,
            '.subjects',
            fn (Builder $sub) => $sub->where('subjects.slug', $slug),
        ));

        $query->when($filters->gradeLevel, fn (Builder $q, string $slug) => self::throughTeacher(
            $q,
            '.gradeLevels',
            fn (Builder $sub) => $sub->where('grade_levels.slug', $slug),
        ));

        $query->when($filters->teacher, fn (Builder $q, string $uuid) => self::throughTeacher(
            $q,
            '',
            fn (Builder $sub) => $sub->where('teacher_profiles.uuid', $uuid),
        ));

        $query->when(
            in_array($filters->type, Course::types(), true) ? $filters->type : null,
            fn (Builder $q, string $type) => $q->where('course_type', $type),
        );

        // No price filter and no price sort (T002 · T089أ): the course price left
        // the browsing surface and stayed on the buyable unit's own page. A range
        // filter is a browsing surface too, and the noisiest one.
    }

    /**
     * Through the course's TEACHER — `Course::teacherProfileForListing()` in
     * SQL: the creator's profile when the creator teaches here (or no profile is
     * recorded), else the recorded one. It read `creator.teacherProfile` alone,
     * so a course an ASSISTANT created was filed under the assistant's subjects
     * and missing from its teacher's.
     *
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    private static function throughTeacher(Builder $query, string $path, Closure $constraint): Builder
    {
        return $query->where(fn (Builder $teacher) => $teacher
            ->where(fn (Builder $own) => $own
                ->whereHas('creator.teacherProfile'.$path, $constraint)
                ->where(fn (Builder $why) => $why
                    ->whereNull('teacher_profile_id')
                    ->orWhere(fn (Builder $teaches) => Course::creatorTeachesClause($teaches))))
            ->orWhere(fn (Builder $recorded) => $recorded
                ->whereHas('teacherProfile'.$path, $constraint)
                ->whereNot(fn (Builder $teaches) => Course::creatorTeachesClause($teaches))));
    }

    /** @param Builder<Course> $query */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            CourseFilterDTO::SORT_NEWEST => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('enrolled_count'),
        };

        $query->orderBy('id');
    }
}
