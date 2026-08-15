<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\SyncExamItems;
use App\Modules\Assessments\Http\Requests\SyncExamItemsRequest;
use App\Modules\Assessments\Http\Resources\ExamItemResource;
use App\Modules\Assessments\Models\Exam;
use DomainException;
use Illuminate\Http\JsonResponse;

class ExamItemsController extends Controller
{
    /**
     * ⚠️ `question` IS NOT OPTIONAL IN THIS EAGER LOAD.
     *
     * `ExamItemResource::effectivePoints()` reads the question's own worth when
     * there is no override, so an unloaded relation is one SELECT per row on the
     * exam builder's main list.
     *
     * @var list<string>
     */
    private const EAGER = ['question.concept', 'question.options'];

    public function index(Exam $exam): JsonResponse
    {
        $this->authorize('manageQuestions', $exam);

        return response()->json([
            'data' => ExamItemResource::collection($exam->items()->with(self::EAGER)->get()),
        ]);
    }

    public function sync(SyncExamItemsRequest $request, Exam $exam, SyncExamItems $action): JsonResponse
    {
        /** @var list<array{uuid: string, points_override?: int|null}> $items */
        $items = $request->validated('items');

        try {
            $action->handle($exam, $items);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => ExamItemResource::collection($exam->items()->with(self::EAGER)->get()),
        ]);
    }
}
