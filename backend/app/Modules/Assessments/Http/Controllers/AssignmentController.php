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
use App\Modules\Tenancy\Support\Permissions;
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
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $manages = $user->can(Permissions::ASSIGNMENTS_MANAGE);

        // Same NULL-ordering reason as the marking list below: an assignment
        // with no deadline is a real row, and it must not lead or trail by
        // accident of database.
        $query = Assignment::query()
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('due_at');

        if ($manages) {
            $query->withCount([
                'submissions as submitted_count' => fn (Builder $q): Builder => $q->whereNotNull('submitted_at'),
                'submissions as pending_count' => fn (Builder $q): Builder => $q->whereNotNull('submitted_at')->whereNull('graded_at'),
            ]);
        } else {
            // Published only, and the reader's own row attached — one eager load
            // rather than a submission lookup per card.
            // ⚠️ `submissions.media` AND NOT JUST `submissions`. The Resource
            // asks every row whether it carries a file, and `getFirstMedia()`
            // lazy-loads once per card — the ClassSessionResource defect in new
            // clothes. Pinned by the query budget in SubmissionStateTest.
            $query->published()->with([
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
            return response()->json(['message' => 'اختر مساحة عمل أولاً.'], 422);
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
            ->with(['student:id,uuid,name', 'assignment:id,uuid,title,points', 'media'])
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
