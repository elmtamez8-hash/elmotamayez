<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Http\Controllers\PracticeController;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Database\Eloquent\Model;
use Tests\Feature\Assessments\PracticeFilterOptionsTest;

/**
 * What «درّب نفسك» may actually be narrowed by, for THIS student.
 *
 * ⚠️ EVERY OPTION IS DERIVED FROM `PracticePool::questionsFor()` — the same query
 * the paper is drawn from — and not from any list that happens to be nearby. The
 * page used to fill its one filter from `GET /manage/bank/concepts`, a TEACHER
 * route: a student is refused it with `403`, so the control rendered for every
 * student on the platform with **no options at all**, and its own comment said so
 * («degrades to any concept»). A `<select>` with nothing in it is a question with
 * no answers, which reads as a page that failed to load.
 *
 * That is spec 009's leaderboard picker exactly: built from the nearest list to
 * hand rather than from the authoriser's own predicate, it offered scopes the API
 * refused and hid scopes it allowed. The rule that came out of it is the rule
 * here — and it is enforced by walking every returned option through the real
 * endpoint in {@see PracticeFilterOptionsTest}.
 *
 * ⚠️ AND A FACET WITH NOTHING IN IT IS RETURNED EMPTY, NEVER FILLED IN FROM
 * SOMEWHERE WIDER. A student with one teacher gets one teacher; a bank with no
 * concepts tagged gets `[]`, and the screen omits the control rather than showing
 * one that cannot narrow anything.
 */
class PracticeFilterOptions
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly PracticePool $pool,
    ) {}

    /**
     * @return array{
     *     teachers: list<array{uuid: string, label: string}>,
     *     courses: list<array{uuid: string, label: string}>,
     *     subjects: list<array{uuid: string, label: string}>,
     *     concepts: list<array{uuid: string, label: string}>,
     *     has_questions: bool
     * }
     */
    public function for(User $student, ?int $contextWorkspaceId, ?string $teacherUuid = null): array
    {
        $readable = $contextWorkspaceId !== null
            ? [$contextWorkspaceId]
            : $this->enrollments->activeWorkspaceIdsFor($student);

        if ($readable === []) {
            return ['teachers' => [], 'courses' => [], 'subjects' => [], 'concepts' => [], 'has_questions' => false];
        }

        /*
        | ⚠️ A TEACHER IS OFFERED ONLY IF THEIR BANK CAN ANSWER. Listing every
        | teacher the student studies with would offer a name that then refuses
        | with «لا أسئلة تطابق ما اخترت» — a control whose only effect is a
        | refusal is worse than no control, because the student reads the refusal
        | as being about their own choices rather than about an empty bank.
        */
        $teachers = [];

        foreach (Workspace::query()->withoutGlobalScopes()->whereIn('id', $readable)->orderBy('name')->get(['id', 'uuid', 'name']) as $workspace) {
            if ($this->pool->questionsFor((int) $workspace->id, $student)->exists()) {
                $teachers[] = ['uuid' => (string) $workspace->uuid, 'label' => (string) $workspace->name];
            }
        }

        /*
        | The course and concept lists belong to ONE teacher's bank, so they are
        | answered for the teacher in play — the named one, or the only one. With
        | several teachers and none named the paper itself is refused, so offering
        | their courses mixed together would be a picker for a request that cannot
        | be made.
        */
        $scope = $this->scopeFor($readable, $teacherUuid);

        if ($scope === null) {
            return [
                'teachers' => $teachers,
                'courses' => [],
                'subjects' => [],
                'concepts' => [],
                'has_questions' => $teachers !== [],
            ];
        }

        $questions = $this->pool->questionsFor($scope, $student);

        // One query each, over the pool's own ids — never a row-by-row lookup,
        // and never the full taxonomy filtered afterwards in PHP.
        $lessonIds = (clone $questions)->distinct()->pluck('lesson_id')->all();
        $conceptIds = (clone $questions)->whereNotNull('concept_id')->distinct()->pluck('concept_id')->all();

        $courseIds = Lesson::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $lessonIds)
            ->distinct()
            ->pluck('course_id')
            ->all();

        $courses = Course::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $courseIds)
            ->orderBy('title')
            ->get(['id', 'uuid', 'title', 'subject_id']);

        return [
            'teachers' => $teachers,
            'courses' => $this->label($courses, 'title'),
            /*
            | The subject, a different axis from the course: one teacher may run
            | «الرياضيات ٩» and «الرياضيات ١٠» as two courses, and a student
            | revising the subject wants both. Reference data, so no workspace
            | condition — and drawn from the courses whose lessons hold pool
            | questions, so a subject that can answer nothing is never offered.
            */
            'subjects' => $this->label(
                Subject::query()
                    ->whereIn('id', $courses->pluck('subject_id')->filter()->unique()->all())
                    ->orderBy('name_ar')
                    ->get(['uuid', 'name_ar']),
                'name_ar',
            ),
            'concepts' => $this->label(
                Concept::query()->withoutWorkspaceScope()->whereIn('id', $conceptIds)->orderBy('name')->get(['uuid', 'name']),
                'name',
            ),
            'has_questions' => (clone $questions)->exists(),
        ];
    }

    /**
     * The one teacher these lists describe, or null when the answer is «choose
     * a teacher first» — the ladder {@see PracticeController}
     * builds the paper with, read the same way so the screen and the door agree.
     *
     * @param  list<int>  $readable
     */
    private function scopeFor(array $readable, ?string $teacherUuid): ?int
    {
        if ($teacherUuid !== null && $teacherUuid !== '') {
            $named = (int) Workspace::query()->withoutGlobalScopes()->where('uuid', $teacherUuid)->value('id');

            return in_array($named, $readable, true) ? $named : null;
        }

        return count($readable) === 1 ? $readable[0] : null;
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
