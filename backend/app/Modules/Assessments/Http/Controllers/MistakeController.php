<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\BuildPracticeFromMistakes;
use App\Modules\Assessments\Http\Resources\AttemptResource;
use App\Modules\Assessments\Http\Resources\MistakeResource;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Support\MistakeNotebook;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's own mistakes, and a paper built from them.
 *
 * ⚠️ ROW OWNERSHIP, NOT A PERMISSION. The student id comes from the token and is
 * never read from the request: a `?student=` parameter here would be FR-020
 * undone by one forgotten check, and there is no reading of somebody else's
 * notebook this endpoint is meant to serve.
 *
 * ⚠️ AND THE NOTEBOOK IS PER TEACHER (FR-016أ). The workspace is the one the
 * student is currently in, answered explicitly rather than defaulted — merging
 * teachers would show half the mistakes and call them all of them, and would put
 * one teacher's question inside another's context.
 */
class MistakeController extends Controller
{
    public function index(Request $request, MistakeNotebook $notebook): JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'اختر مساحة عمل لعرض دفتر أخطائك.'], 422);
        }

        $mistakes = $notebook->paginate(
            $workspaceId,
            (int) $this->currentUser($request)->getKey(),
            $this->filters($request),
            (int) $request->integer('per_page', 20),
        );

        return response()->json([
            // Wrapped by hand: JsonResource::withoutWrapping() is on globally.
            'data' => MistakeResource::collection($mistakes->items()),
            'meta' => [
                'total' => $mistakes->total(),
                'current_page' => $mistakes->currentPage(),
                'last_page' => $mistakes->lastPage(),
            ],
        ]);
    }

    public function practice(Request $request, BuildPracticeFromMistakes $action): JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'اختر مساحة عمل قبل بناء اختبار.'], 422);
        }

        try {
            $attempt = $action->handle(
                $workspaceId,
                $this->currentUser($request),
                $this->filters($request),
                (int) $request->integer('count', 10),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => AttemptResource::make($attempt),
            'questions' => $this->paper($attempt),
        ], 201);
    }

    /**
     * The paper as the student must see it — read from the frozen snapshot.
     *
     * ⚠️ `correct_option_ids` IS IN THAT SNAPSHOT AND IS NOT COPIED OUT HERE.
     * The snapshot exists so grading compares against what was shown; handing
     * the whole of it to the browser publishes the answer key of every question
     * on the page, in the response that opens the page.
     *
     * @return list<array<string, mixed>>
     */
    private function paper(Attempt $attempt): array
    {
        $questions = [];

        foreach ($attempt->items()->orderBy('order')->get() as $item) {
            $snapshot = $item->snapshot;

            $questions[] = [
                'id' => (int) $item->question_id,
                'type' => $snapshot['type'] ?? 'mcq',
                'content' => $snapshot['content'] ?? '',
                'points' => (int) $item->points,
                'options' => array_map(
                    static fn (array $option): array => [
                        'id' => (int) $option['id'],
                        'content' => (string) $option['content'],
                    ],
                    is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [],
                ),
            ];
        }

        return $questions;
    }

    /**
     * @return array{concept?: string, lesson?: string, from?: string, to?: string, include_resolved?: bool}
     */
    private function filters(Request $request): array
    {
        return [
            'concept' => $request->string('concept')->toString(),
            'lesson' => $request->string('lesson')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'include_resolved' => $request->boolean('include_resolved'),
        ];
    }
}
