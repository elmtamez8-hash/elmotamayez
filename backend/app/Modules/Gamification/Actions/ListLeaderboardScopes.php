<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Support\LeaderboardScope;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Support\Collection;

/**
 * Which boards this student may actually open (FR-020).
 *
 * ⚠️ THE SIX SCOPES SHIPPED WITH A PICKER OFFERING ONE. Five of them worked in the
 * API, were tested, and were reachable only by typing a URL — the whole
 * `subject:`/`grade:`/`teacher:`/`course:` half of FR-020 existed and no student
 * could see it. `docs/README.md` listed them as delivered.
 *
 * ⚠️ AND IT IS DERIVED FROM THE SAME PREDICATE THAT AUTHORISES THE READ. The
 * obvious source for "which teachers" is the student's purses, which is wrong in
 * both directions: {@see ReadLeaderboard::workspaceFor()} authorises on an ACTIVE
 * ENROLMENT, while a purse outlives the enrolment (a gone teacher's coins still
 * show, deliberately) and lags it (a new enrolment has no coins yet). A picker
 * built from purses would offer boards the API answers 403 and hide boards it
 * allows — one question spelled two ways, which is the defect
 * `BookingEligibility` already cost this repository once. So this reads
 * {@see EnrollmentDirectory} exactly as the authoriser does.
 *
 * ⚠️ THE GRADE COMES FROM THE COURSES, NOT FROM `student_profiles`. The rollup
 * groups the grade board by `courses.grade_level`, so that column is what decides
 * which board the student is ON. Their profile slug is what they would call their
 * year, and the two can differ — a picker built on the profile would offer a board
 * the student is not in and present it as empty, which reads as a bug.
 *
 * `lesson:` is deliberately absent. A per-lesson ranking is a question asked
 * beside the lesson, and a global picker listing every lesson of every course is a
 * list nobody reads. The scope stays live for a caller who has that context.
 *
 * ⚠️ A NON-STUDENT GETS AN EMPTY LIST, NOT A REFUSAL. Every scope is closed to
 * them — the cross-workspace three by role, the other three for want of an
 * enrolment — so an empty list is the true answer rather than a softened one, and
 * it costs no query.
 *
 * @see ReadLeaderboard for what each option is checked against when it is opened.
 */
class ListLeaderboardScopes extends Action
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    /** @return list<array{scope: string, label: string, kind: string}> */
    public function handle(User $reader): array
    {
        if ($reader->platform_role !== PlatformRole::Student) {
            return [];
        }

        $courseIds = $this->enrollments->activeCourseIdsFor($reader);

        if ($courseIds === []) {
            // Still the platform board: a student with no active enrolment has a
            // rank on it the moment they earn anything outside a workspace, and a
            // focus session is exactly that.
            return [$this->option(LeaderboardScope::Platform, '', 'المنصّة')];
        }

        /*
        | withoutWorkspaceScope, filtered by the ids the directory returned.
        |
        | The reader is a student and belongs to no workspace, so the scope adds no
        | condition here anyway — but saying so explicitly is what keeps this
        | correct if it is ever called with a workspace in context, and the id list
        | is a stricter filter than a workspace one rather than a looser.
        */
        $courses = Course::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $courseIds)
            ->orderBy('title')
            ->get(['id', 'uuid', 'title', 'workspace_id', 'subject_id', 'grade_level']);

        return [
            $this->option(LeaderboardScope::Platform, '', 'المنصّة'),
            ...$this->grades($courses),
            ...$this->subjects($courses),
            ...$this->teachers($courses),
            ...$courses->map(fn (Course $course): array => $this->option(
                LeaderboardScope::Course,
                (string) $course->uuid,
                (string) $course->title,
            ))->all(),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Course>  $courses
     * @return list<array{scope: string, label: string, kind: string}>
     */
    private function grades($courses): array
    {
        $slugs = $courses->pluck('grade_level')->filter()->unique()->values();

        if ($slugs->isEmpty()) {
            return [];
        }

        /** @var Collection<string, string> $names */
        $names = GradeLevel::query()->whereIn('slug', $slugs)->pluck('name_ar', 'slug');

        // array_values: `->all()` on a keyed collection is an array<int, …>, and
        // the declared `list<…>` is what keeps the payload a JSON ARRAY. Without
        // it a client reading `data[0]` gets an object with numeric keys the first
        // time a middle entry is filtered out.
        return array_values($slugs
            // A slug with no row left in the taxonomy still names a live board —
            // the rollup grouped on the column, not on the join — so it is listed
            // under the slug rather than dropped.
            ->map(fn (string $slug): array => $this->option(
                LeaderboardScope::Grade,
                $slug,
                $names->get($slug) ?? $slug,
            ))
            ->all());
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Course>  $courses
     * @return list<array{scope: string, label: string, kind: string}>
     */
    private function subjects($courses): array
    {
        $ids = $courses->pluck('subject_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return array_values(Subject::query()
            ->whereIn('id', $ids)
            ->orderBy('sort_order')
            ->get(['uuid', 'name_ar'])
            // The wire form is the uuid, never the id — a sequential identifier
            // makes the whole space walkable and the scope key is echoed back.
            ->map(fn (Subject $subject): array => $this->option(
                LeaderboardScope::Subject,
                (string) $subject->uuid,
                (string) $subject->name_ar,
            ))
            ->all());
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Course>  $courses
     * @return list<array{scope: string, label: string, kind: string}>
     */
    private function teachers($courses): array
    {
        $ids = $courses->pluck('workspace_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return array_values(Workspace::query()
            ->whereIn('id', $ids)
            // One query for every teacher's name instead of one per workspace.
            ->with('owner:id,first_name,last_name')
            ->get()
            ->map(function (Workspace $workspace): array {
                $owner = $workspace->owner;
                $name = $owner === null ? '' : trim($owner->first_name.' '.$owner->last_name);

                return $this->option(
                    LeaderboardScope::Teacher,
                    (string) $workspace->uuid,
                    // Falls back to the workspace's own name: a teacher account
                    // that is gone still has a board the student is ranked on, and
                    // a blank label would make it unpickable.
                    $name === '' ? (string) $workspace->name : $name,
                );
            })
            ->all());
    }

    /** @return array{scope: string, label: string, kind: string} */
    private function option(LeaderboardScope $scope, string $identifier, string $label): array
    {
        return [
            'scope' => $scope->keyFor($identifier),
            'label' => $label,
            // What KIND of board it is, so the client can group without parsing
            // the key — and without a second copy of the scope vocabulary in
            // TypeScript that drifts the day a scope is added.
            'kind' => $scope->value,
        ];
    }
}
