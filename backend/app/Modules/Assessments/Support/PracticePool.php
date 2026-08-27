<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Question;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a student may be practised on, and what must be held back (FR-022أ).
 *
 * ⚠️ THE EXAM THEY HAVE NOT SAT YET IS SUBTRACTED, AND THIS IS THE WHOLE REASON
 * THE CLASS EXISTS. One bank question serves several exams, so a question the
 * student got wrong last month can also be sitting in next week's paper. Practise
 * it and the platform marks it instantly and shows the explanation (FR-024) —
 * which is the exam, with its answers, handed over a week early.
 *
 * Shared rather than inlined in one action: "test me on my mistakes" and the
 * self-generated exam draw from the same bank and need the identical subtraction.
 * Two copies of this rule is one copy that will be forgotten when a third caller
 * appears.
 */
class PracticePool
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    /**
     * Every question this student may be practised on with this teacher.
     *
     * ⚠️ ONE SPELLING, BECAUSE TWO READERS ASK IT. `BuildSelfExam` draws the
     * paper from this and `PracticeFilterOptions` derives the pickers from it —
     * and a picker assembled beside the query it filters is the defect spec 009's
     * leaderboard paid for: options the API then answers `403`, and options it
     * would have allowed left out. Whatever narrows the pool narrows the choices
     * on the same line.
     *
     * @param  string|null  $courseUuid  narrow to one course; a uuid that names
     *                                   nothing here matches NOTHING rather than
     *                                   quietly returning the unfiltered pool
     * @param  string|null  $subjectUuid  narrow to one subject across courses
     * @return Builder<Question>
     */
    public function questionsFor(int $workspaceId, User $student, ?string $courseUuid = null, ?string $subjectUuid = null): Builder
    {
        $courseIds = $this->enrollments->activeCourseIdsFor($student);

        $lessons = Lesson::query()
            ->withoutWorkspaceScope()
            // Repeated on the lessons: `activeCourseIdsFor` answers across every
            // teacher the student studies with, and one paper is one bank.
            ->where('workspace_id', $workspaceId)
            ->whereIn('course_id', $courseIds === [] ? [0] : $courseIds);

        if ($courseUuid !== null && $courseUuid !== '') {
            $lessons->whereHas('course', fn ($course) => $course->where('uuid', $courseUuid));
        }

        // The subject reaches through the course, so «الرياضيات» gathers every
        // course of it in this bank rather than needing one pick per course.
        if ($subjectUuid !== null && $subjectUuid !== '') {
            $lessons->whereHas('course.subject', fn ($subject) => $subject->where('uuid', $subjectUuid));
        }

        return Question::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('lesson_id', $lessons->select('id'))
            ->where('is_active', true)
            // Marked the moment it is handed in (FR-024), which an essay cannot be.
            ->where('type', '!=', 'essay')
            ->whereNotIn('id', $this->withheldQuestionIds($workspaceId, (int) $student->getKey()));
    }

    /**
     * Question ids this student must not be practised on right now.
     *
     * @return list<int>
     */
    public function withheldQuestionIds(int $workspaceId, int $studentId): array
    {
        /*
        | "Not completed" is the absence of a submitted attempt — not the absence
        | of a passing one. A student who sat the paper and failed has already
        | seen every question on it, so withholding them afterwards protects
        | nothing and blocks revision of exactly the paper they need.
        */
        $satExamIds = Attempt::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $studentId)
            ->where('is_practice', false)
            ->whereNotNull('exam_id')
            ->whereNotNull('submitted_at')
            ->pluck('exam_id')
            ->all();

        $rows = DB::table('exam_items as i')
            ->join('exams as e', 'e.id', '=', 'i.exam_id')
            ->where('i.workspace_id', $workspaceId)
            ->where('e.status', 'published')
            ->when($satExamIds !== [], fn ($query) => $query->whereNotIn('i.exam_id', $satExamIds))
            ->distinct()
            ->pluck('i.question_id');

        $ids = [];

        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
