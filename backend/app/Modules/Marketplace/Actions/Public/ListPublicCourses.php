<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\DTOs\CourseFilterDTO;
use App\Shared\Actions\Action;
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
            ->with(['creator:id,first_name,last_name', 'creator.teacherProfile'])
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

        return $query->paginate(perPage: $filters->perPage, page: $filters->page);
    }

    /** @param Builder<Course> $query */
    private function applyFilters(Builder $query, CourseFilterDTO $filters): void
    {
        // Courses carry no taxonomy of their own. Rather than duplicate the
        // subject/grade pivots onto a second table that could then disagree with
        // the teacher's, the filter reads through the author's profile: a maths
        // teacher's course is a maths course.
        $query->when($filters->subject, fn (Builder $q, string $slug) => $q->whereHas(
            'creator.teacherProfile.subjects',
            fn (Builder $sub) => $sub->where('subjects.slug', $slug),
        ));

        $query->when($filters->gradeLevel, fn (Builder $q, string $slug) => $q->whereHas(
            'creator.teacherProfile.gradeLevels',
            fn (Builder $sub) => $sub->where('grade_levels.slug', $slug),
        ));

        $query->when($filters->teacher, fn (Builder $q, string $uuid) => $q->whereHas(
            'creator.teacherProfile',
            fn (Builder $sub) => $sub->where('teacher_profiles.uuid', $uuid),
        ));

        $query->when(
            in_array($filters->type, Course::types(), true) ? $filters->type : null,
            fn (Builder $q, string $type) => $q->where('course_type', $type),
        );

        $query->when($filters->priceMin, fn (Builder $q, float $min) => $q->where('price', '>=', $min));
        $query->when($filters->priceMax, fn (Builder $q, float $max) => $q->where('price', '<=', $max));
    }

    /** @param Builder<Course> $query */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            CourseFilterDTO::SORT_PRICE => $query->orderBy('price'),
            CourseFilterDTO::SORT_NEWEST => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('enrolled_count'),
        };

        $query->orderBy('id');
    }
}
