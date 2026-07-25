<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\PublishExam;
use App\Modules\Assessments\Http\Requests\StoreExamRequest;
use App\Modules\Assessments\Http\Requests\UpdateExamRequest;
use App\Modules\Assessments\Http\Resources\ExamResource;
use App\Modules\Assessments\Models\Exam;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Exam::class);

        $exams = Exam::query()
            ->where('status', 'published')
            ->when($request->user()?->can('exams.view'), fn ($q) => $q->orWhere('status', '!=', 'published'))
            ->withCount('questions')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json(ExamResource::collection($exams));
    }

    public function show(Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        return response()->json(ExamResource::make($exam->loadCount('questions')));
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $exam = Exam::create(array_merge($request->validated(), [
            'workspace_id' => app(WorkspaceContext::class)->id(),
        ]));

        return response()->json(ExamResource::make($exam), 201);
    }

    public function update(UpdateExamRequest $request, Exam $exam): JsonResponse
    {
        $exam->update($request->validated());

        return response()->json(ExamResource::make($exam->fresh()));
    }

    public function publish(Exam $exam, PublishExam $action): JsonResponse
    {
        $this->authorize('publish', $exam);

        return response()->json(ExamResource::make($action->handle($exam)));
    }

    public function destroy(Exam $exam): JsonResponse
    {
        $this->authorize('delete', $exam);

        $exam->delete();

        return response()->json(null, 204);
    }
}
