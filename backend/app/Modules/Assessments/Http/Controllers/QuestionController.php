<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Http\Requests\StoreQuestionRequest;
use App\Modules\Assessments\Http\Requests\UpdateQuestionRequest;
use App\Modules\Assessments\Http\Resources\QuestionResource;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class QuestionController extends Controller
{
    public function index(Exam $exam): JsonResponse
    {
        $this->authorize('manageQuestions', $exam);

        $questions = $exam->questions()->with('options')->orderBy('id')->get();

        return response()->json(QuestionResource::collection($questions));
    }

    public function store(StoreQuestionRequest $request, Exam $exam): JsonResponse
    {
        $validated = $request->validated();
        $options = $validated['options'] ?? [];
        unset($validated['options']);

        $question = DB::transaction(function () use ($exam, $validated, $options) {
            $question = $exam->questions()->create(array_merge($validated, [
                'workspace_id' => app(WorkspaceContext::class)->id(),
            ]));

            foreach ($options as $option) {
                $question->options()->create(array_merge($option, [
                    'workspace_id' => $exam->workspace_id,
                ]));
            }

            return $question;
        });

        return response()->json(QuestionResource::make($question->load('options')), 201);
    }

    public function update(UpdateQuestionRequest $request, Exam $exam, Question $question): JsonResponse
    {
        if ($question->exam_id !== $exam->getKey()) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        $validated = $request->validated();
        $options = $validated['options'] ?? null;
        unset($validated['options']);

        $question = DB::transaction(function () use ($exam, $question, $validated, $options) {
            $question->update($validated);

            if ($options !== null) {
                $question->options()->delete();

                foreach ($options as $option) {
                    $question->options()->create(array_merge($option, [
                        'workspace_id' => $exam->workspace_id,
                    ]));
                }
            }

            return $question;
        });

        return response()->json(QuestionResource::make($question->load('options')->fresh()));
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
