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
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student sets themselves a paper, and reads it back marked.
 */
class PracticeController extends Controller
{
    public function store(BuildSelfExamRequest $request, BuildSelfExam $action): JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'اختر مساحة عمل قبل توليد اختبار.'], 422);
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
}
