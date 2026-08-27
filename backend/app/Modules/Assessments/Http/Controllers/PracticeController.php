<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\BuildSelfExam;
use App\Modules\Assessments\Data\SelfExamCriteria;
use App\Modules\Assessments\Http\Requests\BuildSelfExamRequest;
use App\Modules\Assessments\Http\Resources\PracticeAttemptResource;
use App\Modules\Assessments\Http\Resources\PracticeResultResource;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Support\PracticeFilterOptions;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student sets themselves a paper, and reads it back marked.
 */
class PracticeController extends Controller
{
    public function store(
        BuildSelfExamRequest $request,
        BuildSelfExam $action,
        EnrollmentDirectory $enrollments,
    ): JsonResponse {
        /*
        | ⚠️ THIS WAS `WorkspaceContext::id()` ALONE, AND IT IS NULL FOR EVERY
        | REAL STUDENT — so the endpoint answered «اختر مساحة عمل قبل توليد
        | اختبار» to the only people it exists for, about a control the product
        | does not give them. Identical to the `/mistakes` defect, one page over,
        | and it sat behind a `403` from the FormRequest that hid it.
        |
        | The ladder is `MistakeController::practiceWorkspace()`'s, spelled the
        | same way and for its reasons: a named teacher wins; ONE readable teacher
        | is chosen silently, because a question with one possible answer is a tap
        | that teaches nothing; several with none named is a refusal that says
        | which control to use rather than picking a teacher for them — a paper
        | built from the alphabetically-first teacher is a revision session about
        | the wrong subject.
        */
        $workspaceId = $this->workspaceFor($request, $enrollments);

        if ($workspaceId === null) {
            return response()->json([
                'message' => 'اختر المدرّس أولاً لبناء ورقة تدريب.',
                'code' => 'teacher_required',
            ], 422);
        }

        $criteria = SelfExamCriteria::fromArray($request->validated());

        try {
            $built = $action->handle($workspaceId, $this->currentUser($request), $criteria);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => PracticeAttemptResource::make(
                $built['attempt'],
                $built['requested'],
                $built['delivered'],
                $criteria->durationMinutes,
            ),
        ], 201);
    }

    /**
     * What this student may narrow their paper by (FR-022).
     *
     * ⚠️ A SEPARATE ENDPOINT RATHER THAN FIELDS ON THE BUILD RESPONSE, because
     * the screen needs the choices BEFORE it can ask for a paper — and the page
     * used to fill them from a teacher-only route that answers a student `403`,
     * so the one filter it drew was empty for everybody who could see it.
     */
    public function options(Request $request, PracticeFilterOptions $options): JsonResponse
    {
        return response()->json([
            'data' => $options->for(
                $this->currentUser($request),
                app(WorkspaceContext::class)->id(),
                $request->string('teacher')->toString(),
            ),
        ]);
    }

    /**
     * The marked paper with its explanations (FR-024).
     */
    public function result(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);

        /*
        | ⚠️ PRACTICE ONLY, AND THIS LINE IS THE GUARD. The payload carries the
        | correct answer and the explanation of every question on the paper. On a
        | teacher's exam that is the answer key of a paper the rest of the class
        | may not have sat yet — and `AttemptPolicy::view` deliberately lets a
        | teacher read a student's attempt, so ownership alone does not save it.
        */
        abort_unless($attempt->is_practice, 404);

        return response()->json(['data' => PracticeResultResource::make($attempt)]);
    }

    /**
     * The single teacher this paper is built for, or null to refuse.
     *
     * Context first, enrolments second — the {@see StudentScope} shape: a reader
     * who HAS a context (a teacher trying their own bank, an invited member)
     * keeps exactly the behaviour they had, and a real student gets the teachers
     * they actually study with instead of a refusal.
     */
    private function workspaceFor(Request $request, EnrollmentDirectory $enrollments): ?int
    {
        $context = app(WorkspaceContext::class)->id();

        $readable = $context !== null
            ? [$context]
            : $enrollments->activeWorkspaceIdsFor($this->currentUser($request));

        $teacher = $request->string('teacher')->toString();

        if ($teacher !== '') {
            $named = (int) Workspace::query()->where('uuid', $teacher)->value('id');

            // A uuid that is not one of theirs refuses, rather than falling
            // through to a silently different teacher's bank.
            return in_array($named, $readable, true) ? $named : null;
        }

        return count($readable) === 1 ? $readable[0] : null;
    }
}
