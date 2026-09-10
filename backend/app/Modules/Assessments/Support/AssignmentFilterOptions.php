<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The teachers and subjects this student actually has homework from.
 *
 * ⚠️ DERIVED FROM THE LIST'S OWN QUERY — `StudentScope` plus `published()`, the
 * two predicates `AssignmentController@index` narrows by — and not from the
 * student's enrolments. The two differ, and each difference is a defect on the
 * screen: a course they are enrolled in with no homework set would be an option
 * that empties the list, and a teacher whose only assignment is a DRAFT would be
 * a name that filters to nothing at all. A picker is derived from the
 * authoriser's own predicate; spec 009's leaderboard paid for the other way and
 * so did the practice page's dead concept list.
 *
 * ⚠️ AND THERE IS NO `state` FACET HERE. A submission state is not a column — it
 * is read off the reader's own row against the deadline — so a server-side facet
 * would be a second computation of something the payload already carries per
 * card. The screen narrows by it in the browser, over rows it is already holding.
 */
class AssignmentFilterOptions
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly CohortDirectory $cohorts,
    ) {}

    /**
     * @return array{
     *     teachers: list<array{uuid: string, label: string}>,
     *     courses: list<array{uuid: string, label: string}>,
     *     subjects: list<array{uuid: string, label: string}>,
     *     cohorts: list<array{uuid: string, label: string, course_uuid: string}>
     * }
     */
    public function for(User $student): array
    {
        $base = fn (): Builder => StudentScope::applyIfUnscoped(
            Assignment::query()->published(),
            $student,
            $this->enrollments,
        );

        // Two ids lists off the same predicate, then two labelled reads. Never a
        // lookup per row, and never the full catalogue filtered in PHP.
        $courseIds = $base()->whereNotNull('course_id')->distinct()->pluck('course_id')->all();
        $workspaceIds = $base()->distinct()->pluck('workspace_id')->all();

        $courses = Course::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $courseIds)
            ->orderBy('title')
            ->get(['id', 'uuid', 'title', 'subject_id']);

        return [
            'teachers' => $this->label(
                Workspace::query()->withoutGlobalScopes()->whereIn('id', $workspaceIds)->orderBy('name')->get(['uuid', 'name']),
                'name',
            ),
            'courses' => $this->label($courses, 'title'),
            /*
            | ⚠️ THE SUBJECT IS A DIFFERENT AXIS FROM THE COURSE, which is why it
            | is worth its own control: «الرياضيات» is one subject taught by three
            | teachers as three courses, and a student revising for a maths test
            | wants all three. Reference data, so no workspace condition — and
            | drawn from the courses IN THE LIST, so a subject with no homework in
            | it is never offered.
            */
            'subjects' => $this->label(
                Subject::query()
                    ->whereIn('id', $courses->pluck('subject_id')->filter()->unique()->all())
                    ->orderBy('name->'.app()->getLocale())
                    ->get(['uuid', 'name']),
                'name',
            ),
            'cohorts' => $this->cohortOptions($student, $courses),
        ];
    }

    /**
     * The groups this student is in, among the courses that set homework.
     *
     * ⚠️ IT CARRIES ITS COURSE, AND THE SCREEN NEEDS THAT MORE THAN THE FILTER
     * DOES. A student holds at most ONE open membership per course, so narrowing
     * by a group selects exactly the rows narrowing by its course would — the
     * control is a second name for the same cut. What is NOT available anywhere
     * else is the group's NAME beside a homework, which is how a student refers
     * to their own timetable («واجب مجموعة السبت»), and `course_uuid` is what lets
     * the card render it without a lookup per row.
     *
     * @param  Collection<int, Course>  $courses
     * @return list<array{uuid: string, label: string, course_uuid: string}>
     */
    private function cohortOptions(User $student, $courses): array
    {
        $mine = $this->cohorts->openMembershipCohortIdsFor($student);

        if ($mine === []) {
            return [];
        }

        $byId = $courses->keyBy('id');

        $rows = [];

        foreach (Cohort::query()->withoutWorkspaceScope()->whereIn('id', $mine)->orderBy('name')->get(['uuid', 'name', 'course_id']) as $cohort) {
            $course = $byId->get($cohort->course_id);

            // A group whose course sets no homework is not an option here: it
            // would filter the list to nothing.
            if ($course === null) {
                continue;
            }

            $rows[] = [
                'uuid' => (string) $cohort->uuid,
                'label' => (string) $cohort->name,
                'course_uuid' => (string) $course->uuid,
            ];
        }

        return $rows;
    }

    /**
     * @param  iterable<Model>  $rows
     * @return list<array{uuid: string, label: string}>
     */
    private function label(iterable $rows, string $column): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'uuid' => (string) $row->getAttribute('uuid'),
                'label' => (string) $row->getAttribute($column),
            ];
        }

        return $out;
    }
}
