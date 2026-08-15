<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Data\SelfExamCriteria;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Support\PracticePaper;
use App\Modules\Assessments\Support\PracticePool;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use DomainException;

/**
 * The student builds their own paper from the bank (FR-021).
 *
 * ⚠️ THE POOL IS WHAT AN ACTIVE ENROLMENT ENTITLES, NOT WHAT MEMBERSHIP DOES
 * (FR-022). A student whose enrolment ended keeps their workspace membership —
 * the row is how they still read their certificates and their old orders — so
 * asking "are they a member?" hands the teacher's entire bank to somebody who
 * stopped paying last term. The question is asked of `EnrollmentDirectory`,
 * which is Learning's to answer.
 *
 * ⚠️ AND A QUESTION TIED TO NO LESSON IS OUT OF REACH BY CONSTRUCTION. Entitle-
 * ment travels lesson → course → enrolment, so an untagged question belongs to
 * no course and therefore to nobody's enrolment. Including it "because it is in
 * the same workspace" is the membership test wearing a different hat.
 */
class BuildSelfExam extends Action
{
    /** Nobody revises usefully past this, and every item is a written row. */
    private const MAX_QUESTIONS = 30;

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly PracticePool $pool,
        private readonly PracticePaper $paper,
    ) {}

    /**
     * @return array{attempt: Attempt, requested: int, delivered: int}
     *
     * @throws DomainException when the criteria match nothing they may sit
     */
    public function handle(int $workspaceId, User $student, SelfExamCriteria $criteria): array
    {
        $requested = max(1, min($criteria->count, self::MAX_QUESTIONS));

        $lessonIds = $this->entitledLessonIds($workspaceId, $student);

        if ($lessonIds === []) {
            throw new DomainException('لا دروس مسجَّلاً فيها الآن، فلا أسئلة تُسحب.');
        }

        $query = Question::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('lesson_id', $lessonIds)
            ->where('is_active', true)
            // Marked the moment it is handed in (FR-024), which an essay cannot be.
            ->where('type', '!=', 'essay')
            ->whereNotIn('id', $this->pool->withheldQuestionIds($workspaceId, (int) $student->getKey()));

        if ($criteria->conceptUuid !== null) {
            $query->where('concept_id', $this->conceptId($workspaceId, $criteria->conceptUuid));
        }

        if ($criteria->difficulty !== null) {
            $query->where('difficulty', $criteria->difficulty);
        }

        /*
        | ⚠️ RANDOMISED IN SQL, and the ordering is the feature. Taking the first
        | N by id hands the same student the same paper every time they ask with
        | the same criteria — which turns "generate me a test" into "show me the
        | oldest ten questions again".
        */
        $questions = $query->with('options')->inRandomOrder()->limit($requested)->get();

        if ($questions->isEmpty()) {
            throw new DomainException('لا أسئلة تطابق ما اخترت. جرّب فكرةً أخرى أو صعوبةً أخرى.');
        }

        /*
        | ⚠️ FR-023: SHORT IS AN ANSWER, NOT A FAILURE — and the shortfall is
        | REPORTED. A generator that quietly returns four questions for a request
        | of ten teaches the student that the number they typed does nothing, and
        | they read the score out of four as a score out of ten.
        */
        return [
            'attempt' => $this->paper->write($workspaceId, $student, $questions),
            'requested' => $requested,
            'delivered' => $questions->count(),
        ];
    }

    /**
     * The lessons of the courses this student is actively enrolled in, here.
     *
     * @return list<int>
     */
    private function entitledLessonIds(int $workspaceId, User $student): array
    {
        $courseIds = $this->enrollments->activeCourseIdsFor($student);

        if ($courseIds === []) {
            return [];
        }

        $ids = [];

        // The workspace filter is repeated on the lessons: `activeCourseIdsFor`
        // answers across every teacher the student studies with, and this paper
        // is drawn from one teacher's bank.
        foreach (Lesson::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('course_id', $courseIds)
            ->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * Zero when the uuid names nothing here — a filter pointing at another
     * teacher's concept must return an empty paper, never the unfiltered one.
     */
    private function conceptId(int $workspaceId, string $uuid): int
    {
        return (int) Concept::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('uuid', $uuid)
            ->value('id');
    }
}
