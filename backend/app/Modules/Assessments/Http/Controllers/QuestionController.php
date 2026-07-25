<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\SaveQuestion;
use App\Modules\Assessments\Http\Requests\StoreQuestionRequest;
use App\Modules\Assessments\Http\Requests\UpdateQuestionRequest;
use App\Modules\Assessments\Http\Resources\QuestionResource;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use Illuminate\Http\JsonResponse;

class QuestionController extends Controller
{
    public function index(Exam $exam): JsonResponse
    {
        $this->authorize('manageQuestions', $exam);

        $questions = $exam->questions()->with('options')->orderBy('id')->get();

        return response()->json(QuestionResource::collection($questions));
    }

    public function store(StoreQuestionRequest $request, Exam $exam, SaveQuestion $action): JsonResponse
    {
        $validated = $request->validated();
        $options = $validated['options'] ?? [];
        unset($validated['options']);

        $question = $action->create($exam, $validated, $options);

        return response()->json(QuestionResource::make($question), 201);
    }

    public function update(UpdateQuestionRequest $request, Exam $exam, Question $question, SaveQuestion $action): JsonResponse
    {
        if ($question->exam_id !== $exam->getKey()) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        $validated = $request->validated();
        $options = $validated['options'] ?? null;
        unset($validated['options']);

        $question = $action->update($question, $validated, $options);

        return response()->json(QuestionResource::make($question));
    }

    public function destroy(Exam $exam, Question $question): JsonResponse
    {
        $this->authorize('manageQuestions', $exam);

        if ($question->exam_id !== $exam->getKey()) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        $question->delete();

        return response()->json(null, 204);
    }
}
