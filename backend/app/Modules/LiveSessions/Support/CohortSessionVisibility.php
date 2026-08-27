<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which sessions a student may be OFFERED (Q3 · FR-025 · FR-025ج).
 *
 * ⚠️ ONE SPELLING FOR THREE DOORS. A student reaches the timetable through
 * `/courses/{course}/sessions`, through `/courses/{course}/next-session` and —
 * if they ever hold `SESSIONS_VIEW` — through `/class-sessions`. Three copies of
 * this predicate would answer three different questions the first time one of
 * them was edited, which is the two-spellings defect this repository has paid
 * for in `BookingEligibility`, in `ListLeaderboardScopes` and in the recording
 * `IssuePlaybackGrant` allowed while `accessTo()` refused.
 *
 * ⚠️ AND IT IS NOT «حصصي». `GetStudentSchedule` is built from the student's own
 * bookings and is deliberately untouched (FR-025د): a seat already held, an
 * attendance already recorded and a recording already earned are facts, and a
 * judgement about what is on offer may not reach back and take one away.
 *
 * ⚠️ AN UNASSIGNED SESSION IS HIDDEN ONLY IN A COURSE THAT HAS GROUPS. Every
 * session in this database predates the group and carries `cohort_id = null`; a
 * bare `whereNotNull` would empty the timetable of the entire product overnight.
 * That second arm IS FR-036.
 */
final class CohortSessionVisibility
{
    /**
     * @param  Builder<ClassSession>  $query
     * @return Builder<ClassSession>
     */
    public static function apply(Builder $query, User $student): Builder
    {
        $cohorts = app(CohortDirectory::class);

        // Two bulk reads and no per-row question: the list is built before a
        // single row is known, so it cannot ask course by course. Both come
        // through the contract — this module never imports a `Cohort`.
        $mine = $cohorts->openMembershipCohortIdsFor($student);
        $gated = $cohorts->coursesWithCohorts(app(EnrollmentDirectory::class)->activeCourseIdsFor($student));

        return $query->where(fn (Builder $inner): Builder => $inner
            // Fails toward EMPTY: `whereIn(…, [])` matches nothing, so a student
            // in no group sees the ungrouped courses only — never, through a
            // silently ignored filter, everybody's calendar.
            ->whereIn('cohort_id', $mine)
            ->orWhere(fn (Builder $ungrouped): Builder => $ungrouped
                ->whereNull('cohort_id')
                /*
                 | ⚠️ `NULL NOT IN (…)` IS NULL, WHICH IS NOT TRUE. A session with
                 | no course at all — a one-off scheduled outside any course —
                 | would silently vanish from every student's list, and nothing
                 | would say why.
                 */
                ->where(fn (Builder $course): Builder => $course
                    ->whereNull('course_id')
                    ->orWhereNotIn('course_id', $gated))));
    }
}
