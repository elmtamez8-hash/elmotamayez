<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Http\Requests\SubmitAttemptRequest;
use App\Modules\Assessments\Http\Resources\AttemptResource;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttemptController extends Controller
{
    public function start(Request $request, Exam $exam, StartAttempt $action): JsonResponse
    {
        $this->authorize('view', $exam);

        if (! $exam->isPublished()) {
            return response()->json(['message' => 'Exam is not available.'], 422);
        }

        // A used-up attempt allowance throws DomainException, rendered as 422.
        $attempt = $action->handle($exam, $this->currentUser($request));

        $questions = $action->questionsForAttempt($attempt)->map(function ($q) use ($action, $attempt) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'content' => $q->content,
                'points' => $q->points,
                'options' => $action->optionsForQuestion($attempt, $q->options)->map(fn ($o) => [
                    'id' => $o->id,
                    'content' => $o->content,
                ]),
            ];
        });

        return response()->json([
            'attempt' => AttemptResource::make($attempt),
            'questions' => $questions,
        ], 201);
    }

    public function submit(SubmitAttemptRequest $request, Attempt $attempt, GradeAttempt $action): JsonResponse
    {
        $this->authorize('submit', $attempt);

        if ($attempt->isGraded()) {
            return response()->json(['message' => 'This attempt has already been submitted.'], 422);
        }

        $graded = $action->handle($attempt, $request->validated('answers'));

        return response()->json(AttemptResource::make($graded));
    }

    public function show(Attempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);

        return response()->json(AttemptResource::make($attempt->load('answers.question')));
    }
}
