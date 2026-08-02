<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Marketplace\DTOs\TeacherFilterDTO;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Anonymous, cross-workspace teacher listing.
 *
 * The query MUST start from publiclyListed(). WorkspaceScope contributes nothing
 * here — it returns early when there is no authenticated user — so dropping that
 * scope does not narrow the result set, it publishes every workspace's drafts.
 */
class ListPublicTeachers extends Action
{
    /** @return LengthAwarePaginator<int, TeacherProfile> */
    public function handle(TeacherFilterDTO $filters): LengthAwarePaginator
    {
        $query = TeacherProfile::query()
            ->publiclyListed()
            ->with(['user:id,first_name,last_name', 'subjects', 'gradeLevels', 'availabilitySlots']);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters->sort);

        return $query->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );
    }

    /** @param Builder<TeacherProfile> $query */
    private function applyFilters(Builder $query, TeacherFilterDTO $filters): void
    {
        // Taxonomy is matched by slug, not id: the same subject exists as a separate
        // row in every workspace, so an id filter would only ever match one of them.
        $query->when($filters->subject, fn (Builder $q, string $slug) => $q->whereHas(
            'subjects',
            fn (Builder $sub) => $sub->where('subjects.slug', $slug),
        ));

        $query->when($filters->gradeLevel, fn (Builder $q, string $slug) => $q->whereHas(
            'gradeLevels',
            fn (Builder $sub) => $sub->where('grade_levels.slug', $slug),
        ));

        $query->when($filters->priceMin, fn (Builder $q, float $min) => $q->where('hourly_rate', '>=', $min));
        $query->when($filters->priceMax, fn (Builder $q, float $max) => $q->where('hourly_rate', '<=', $max));
        $query->when($filters->minRating, fn (Builder $q, float $min) => $q->where('average_rating', '>=', $min));

        // A null score means "still building", not "scored zero" (FR-024). Asking
        // for a minimum therefore excludes those teachers instead of comparing
        // against a number they do not have — `null >= 60` is null, not false, and
        // relying on that is a bug waiting for a different database.
        $query->when(
            $filters->minTrustScore !== null,
            fn (Builder $q) => $q->whereNotNull('trust_score')
                ->where('trust_score', '>=', $filters->minTrustScore),
        );

        $query->when($filters->language, fn (Builder $q, string $lang) => $q->whereJsonContains('teaching_languages', $lang));

        // Matched against the denormalised copy on this table, not through
        // whereHas('user'): at 50k teachers the correlated subquery cost 1116 ms
        // against SC-008's 1000 ms budget, and the paginator pays it twice (once
        // to count, once to select). Reading the column costs 410 ms. Still not
        // Scout — mixing a search-engine result set with these SQL filters would
        // need a second source of truth to stay consistent.
        $query->when($filters->search, function (Builder $q, string $term): void {
            $q->where('teacher_profiles.search_name', 'like', "%{$term}%");
        });

        $query->when($filters->availableNow, function (Builder $q): void {
            $now = now('UTC');

            $q->whereHas('availabilitySlots', function (Builder $sub) use ($now): void {
                $sub->where('day_of_week', (int) $now->format('w'))
                    ->where('start_time', '<=', $now->format('H:i:s'))
                    ->where('end_time', '>', $now->format('H:i:s'));
            });
        });
    }

    /** @param Builder<TeacherProfile> $query */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            TeacherFilterDTO::SORT_PRICE => $query->orderBy('hourly_rate'),
            // Teachers still building a score sort last rather than being ranked as
            // if they scored zero (FR-026).
            TeacherFilterDTO::SORT_TRUST => $query->orderByRaw('trust_score is null')
                ->orderByDesc('trust_score'),
            default => $query->orderByRaw('average_rating is null')
                ->orderByDesc('average_rating'),
        };

        $query->orderBy('id');
    }
}
