<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assessments\Actions\GradeSubmission;
use App\Modules\Assessments\Actions\GrantExtension;
use App\Modules\Assessments\Actions\SaveAssignment;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Http\Requests\GradeSubmissionRequest;
use App\Modules\Assessments\Http\Requests\GrantExtensionRequest;
use App\Modules\Assessments\Http\Requests\SaveAssignmentRequest;
use App\Modules\Assessments\Http\Requests\SubmitAssignmentRequest;
use App\Modules\Assessments\Http\Resources\AssignmentResource;
use App\Modules\Assessments\Http\Resources\SubmissionResource;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Assessments\Support\AssignmentFilterOptions;
use App\Modules\Assessments\Support\StudentScope;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Homework: what is set, what came in, and what it scored.
 *
 * ⚠️ THE STUDENT'S LIST AND THE TEACHER'S ARE ONE ENDPOINT WITH TWO BRANCHES,
 * and the branch is a permission rather than a route. Two routes over the same
 * table is two places to forget the draft filter — and the teacher's list is the
 * one that legitimately includes drafts.
 */
class AssignmentController extends Controller
{
    public function index(Request $request, EnrollmentDirectory $enrollments): JsonResponse
    {
        $user = $this->currentUser($request);
        $manages = $user->can(Permissions::ASSIGNMENTS_MANAGE);

        // Same NULL-ordering reason as the marking list below: an assignment
        // with no deadline is a real row, and it must not lead or trail by
        // accident of database.
        $query = Assignment::query()
            /*
             | ⚠️ MATCHED THROUGH THE RELATION, SO AN UNKNOWN UUID MATCHES NOTHING
             | — the `ClassSessionController@index` idiom and its reasons. No
             | `exists:` rule (Laravel's is a raw query with no tenant condition),
             | and a filter whose value cannot be resolved empties the list rather
             | than silently returning the unfiltered one.
             */
            ->when(
                $request->query('course'),
                fn (Builder $q, $uuid): Builder => $q->whereHas('course', fn (Builder $course): Builder => $course->where('uuid', $uuid)),
            )
            /*
            | The teacher, matched through the workspace relation for the reason
            | the course filter is: an unresolvable uuid must empty the list, never
            | silently return the unfiltered one.
            */
            ->when(
                $request->query('teacher'),
                fn (Builder $q, $uuid): Builder => $q->whereHas('workspace', fn (Builder $w): Builder => $w->where('uuid', $uuid)),
            )
            /*
            | The subject — a different axis from the course, matched through the
            | course's own relation. «الرياضيات» is one subject taught by three
            | teachers as three courses, and this is the filter that gathers them.
            */
            ->when(
                $request->query('subject'),
                fn (Builder $q, $uuid): Builder => $q->whereHas('course.subject', fn (Builder $s): Builder => $s->where('uuid', $uuid)),
            )
            /*
            | ⚠️ THE GROUP RESOLVES TO ITS COURSE, and that is the honest shape of
            | it: a student holds at most one open membership per course, so this
            | selects exactly what the course filter would. It exists because a
            | student refers to their own timetable by the group's name, not the
            | course's — and an unresolvable uuid empties the list, the idiom every
            | other filter here follows.
            */
            ->when(
                $request->query('cohort'),
                fn (Builder $q, $uuid): Builder => $q->whereHas('course', fn (Builder $c): Builder => $c->whereIn(
                    'id',
                    Cohort::query()->withoutWorkspaceScope()->where('uuid', $uuid)->select('course_id'),
                )),
            )
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('due_at');

        if ($manages) {
            $query->withCount([
                'submissions as submitted_count' => fn (Builder $q): Builder => $q->whereNotNull('submitted_at'),
                'submissions as pending_count' => fn (Builder $q): Builder => $q->whereNotNull('submitted_at')->whereNull('graded_at'),
            ]);
        } else {
            /*
             | ⚠️ THE READER'S OWN SLICE, WITHOUT WHICH THERE IS NO TENANT
             | CONDITION AT ALL. `WorkspaceScope` adds nothing when the context is
             | null, which it always is for a student — so this branch answered
             | any signed-in student with every published deadline on the
             | PLATFORM: other teachers' titles, due dates and point values. See
             | {@see StudentScope}, which carries the measurement and the reason
             | a course-less assignment needs a second predicate.
             */
            StudentScope::applyIfUnscoped($query, $user, $enrollments);

            // Published only, and the reader's own row attached — one eager load
            // rather than a submission lookup per card.
            // ⚠️ `submissions.media` AND NOT JUST `submissions`. The Resource
            // asks every row whether it carries a file, and `getFirstMedia()`
            // lazy-loads once per card — the ClassSessionResource defect in new
            // clothes. Pinned by the query budget in SubmissionStateTest.
            /*
            | ⚠️ `course:id,uuid,title` AND `workspace:id,uuid,name` ARE EAGER
            | LOADED, NOT REACHED PER ROW. The Resource asks both for every card,
            | and a relation resolved inside a Resource is an N+1 by construction —
            | the `ClassSessionResource` defect through a new door. The constrained
            | column lists name the columns the RESOURCE prints, which is the T186
            | lesson: `users` has no `name` column, so a list that omits the two
            | the accessor reads renders a blank in six places across four modules.
            | `workspaces.name` is a real column and is safe to name.
            */
            $query->published()->with([
                'course:id,uuid,title',
                'workspace:id,uuid,name',
                'submissions' => fn ($q) => $q->where('student_user_id', $user->getKey())->with('media'),
            ]);
        }

        $page = $query->paginate(min(50, max(5, (int) $request->integer('per_page', 20))));

        return response()->json([
            'data' => AssignmentResource::collection($page->items()),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * What this student's homework list may be narrowed by.
     *
     * Students only by construction rather than by a check: a teacher's list is
     * already one workspace, so the facets would be one name and one course —
     * and `StudentScope::applyIfUnscoped` leaves a reader with a context exactly
     * as it found them, so the answer is honest for both.
     */
    public function filters(Request $request, AssignmentFilterOptions $options): JsonResponse
    {
        return response()->json(['data' => $options->for($this->currentUser($request))]);
    }

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);

        $user = $this->currentUser($request);

        if (! $user->can(Permissions::ASSIGNMENTS_MANAGE)) {
            $assignment->load(['submissions' => fn ($q) => $q->where('student_user_id', $user->getKey())->with('media')]);
        }

        return response()->json(['data' => AssignmentResource::make($assignment)]);
    }

    public function store(SaveAssignmentRequest $request, SaveAssignment $action): JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'تعذّر تحديد مكان عملك. أعد تحميل الصفحة.'], 422);
        }

        try {
            $assignment = $action->handle($workspaceId, $this->currentUser($request), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => AssignmentResource::make($assignment)], 201);
    }

    public function update(SaveAssignmentRequest $request, Assignment $assignment, SaveAssignment $action): JsonResponse
    {
        $this->authorize('update', $assignment);

        try {
            $assignment = $action->handle(
                (int) $assignment->workspace_id,
                $this->currentUser($request),
                $request->validated(),
                $assignment,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => AssignmentResource::make($assignment)]);
    }

    public function publish(Request $request, Assignment $assignment, SaveAssignment $action): JsonResponse
    {
        $this->authorize('update', $assignment);

        try {
            $assignment = $action->publish($assignment);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => AssignmentResource::make($assignment)]);
    }

    /** Everything handed in for one assignment — the marking list. */
    public function submissions(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('manage', $assignment);

        $submissions = $assignment->submissions()
            ->with(['student:id,uuid,first_name,last_name', 'assignment:id,uuid,title,points', 'media'])
            /*
             | ⚠️ NULLS LAST, EXPLICITLY. A row the sweep wrote has no
             | `submitted_at`, and NULL sorts BEFORE every value on SQLite and
             | AFTER on MySQL — so an unqualified `orderByDesc` puts the students
             | who handed nothing in at the top of the marking list on one
             | database and the bottom on the other.
             */
            ->orderByRaw('CASE WHEN submitted_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('submitted_at')
            ->get();

        return response()->json(['data' => SubmissionResource::collection($submissions)]);
    }

    public function submit(SubmitAssignmentRequest $request, Assignment $assignment, SubmitAssignment $action): JsonResponse
    {
        $this->authorize('view', $assignment);

        try {
            $submission = $action->handle(
                $assignment,
                $this->currentUser($request),
                $request->string('answer_text')->toString() ?: null,
                $request->file('file') instanceof UploadedFile ? $request->file('file') : null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => SubmissionResource::make($submission->load('assignment'))], 201);
    }

    public function grade(GradeSubmissionRequest $request, Submission $submission, GradeSubmission $action): JsonResponse
    {
        $this->authorize('grade', $submission);

        try {
            $submission = $action->handle(
                $submission,
                $this->currentUser($request),
                (float) $request->validated('score'),
                $request->string('feedback')->toString() ?: null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => SubmissionResource::make($submission->load('assignment'))]);
    }

    /**
     * A later date for one student on one assignment (FR-047).
     *
     * ⚠️ 404 FOR BOTH FAILURES. "No such student" and "not your student" answer
     * the same, because a distinct reply for the second confirms the uuid names
     * somebody real — which is the identity probe the guard exists to close.
     */
    public function extend(GrantExtensionRequest $request, Assignment $assignment, GrantExtension $action): JsonResponse
    {
        $this->authorize('manage', $assignment);

        $student = User::query()
            ->where('uuid', $request->string('student_uuid')->toString())
            ->first();

        abort_if($student === null, 404);

        try {
            $submission = $action->handle(
                $assignment,
                $this->currentUser($request),
                $student,
                CarbonImmutable::parse($request->string('until')->toString()),
            );
        } catch (DomainException $exception) {
            // The directory's refusal arrives here, and it must not be told
            // apart from the missing-student case above.
            abort(404);
        }

        return response()->json(['data' => SubmissionResource::make($submission)]);
    }
}
