<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The workspace's latest handed-in exam papers, newest first
 * (`GET /manage/attempts`).
 *
 * ⚠️ THE WORKSPACE IS AN ARGUMENT, NEVER THE AMBIENT SCOPE. `WorkspaceScope`
 * adds no condition when the context is null, and `BasePolicy::before()` lets a
 * super admin through — so a platform owner operating globally would have read
 * every workspace's papers under one title. The caller names the workspace and
 * the query repeats it.
 *
 * ⚠️ NO ROW HERE IS ONE `AttemptPolicy::view()` WOULD REFUSE. The list mirrors
 * that policy's enrolment line — an attempt made inside an enrolment, or one by a
 * student this workspace still teaches — so a paper the student generated for
 * themselves never appears, and neither does a practice run.
 *
 * ⚠️ A CONFINED ASSISTANT READS THE PAPERS OF THEIR OWN COURSES ONLY, and an
 * exam set for the workspace at large (no course) is outside every confinement —
 * the null branch `AssistantScopeDirectory::mayActOnCourse()` documents.
 */
class ListStaffAttempts extends Action
{
    /** What a staff reader counts as «handed in»: marked, or waiting on an essay. */
    public const STATUSES = [Attempt::STATUS_PENDING_GRADING, Attempt::STATUS_GRADED];

    public function __construct(private readonly AssistantScopeDirectory $assistants) {}

    /**
     * @param  bool  $withStudents  false when grading is anonymous (FR-033): the
     *                              relation is not loaded at all, because a name
     *                              fetched and then dropped is a name that travelled.
     * @return LengthAwarePaginator<int, Attempt>
     */
    public function handle(User $reader, int $workspaceId, int $perPage, bool $withStudents): LengthAwarePaginator
    {
        $scoped = $this->assistants->scopedCourseIdsFor($reader, $workspaceId);

        $query = Attempt::query()
            ->withoutWorkspaceScope()
            ->where('exam_attempts.workspace_id', $workspaceId)
            ->whereIn('exam_attempts.status', self::STATUSES)
            ->where('exam_attempts.is_practice', false)
            ->whereNotNull('exam_attempts.exam_id')
            ->where(fn (Builder $attempts): Builder => $attempts
                ->whereNotNull('exam_attempts.enrollment_id')
                ->orWhereExists(fn (QueryBuilder $enrollments): QueryBuilder => $enrollments
                    ->selectRaw('1')
                    ->from((new Enrollment)->getTable())
                    ->whereColumn('enrollments.student_user_id', 'exam_attempts.student_user_id')
                    ->where('enrollments.workspace_id', $workspaceId)
                    ->whereIn('enrollments.status', Enrollment::GRANTING_STATUSES)))
            ->when($scoped !== null, fn (Builder $attempts): Builder => $attempts->whereIn(
                'exam_attempts.exam_id',
                Exam::query()
                    ->withoutWorkspaceScope()
                    ->select('id')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('course_id', $scoped ?? []),
            ))
            // A deleted course comes back null, and the Resource says so rather
            // than failing on it.
            ->with(['exam:id,uuid,title,course_id', 'exam.course:id,uuid,title'])
            ->orderByDesc('exam_attempts.submitted_at')
            ->orderByDesc('exam_attempts.id');

        if ($withStudents) {
            // `name` is an accessor, not a column — selecting it renders a blank name.
            $query->with('student:id,uuid,first_name,last_name');
        }

        return $query->paginate($perPage);
    }
}
