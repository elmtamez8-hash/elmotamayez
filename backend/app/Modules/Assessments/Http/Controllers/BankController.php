<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\DisableQuestion;
use App\Modules\Assessments\Actions\SaveQuestion;
use App\Modules\Assessments\Data\SaveQuestionData;
use App\Modules\Assessments\Http\Requests\SaveQuestionRequest;
use App\Modules\Assessments\Http\Resources\BankQuestionResource;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Support\BankSearch;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankController extends Controller
{
    /**
     * ⚠️ THE EAGER-LOAD PLAN IS DECLARED HERE AND NOWHERE ELSE.
     *
     * A Resource runs once per row, so every relation `BankQuestionResource`
     * reads has to be loaded before it starts — and the bank list is the page
     * most likely to be twenty rows deep. `withCount('examItems')` is applied
     * inside {@see BankSearch} because both of its branches need it.
     *
     * @var list<string>
     */
    private const EAGER = ['concept', 'lesson', 'options'];

    public function index(Request $request, BankSearch $search): JsonResponse
    {
        $this->authorize('viewAny', Question::class);

        $questions = $search->paginate([
            'q' => $request->string('q')->toString(),
            'concept' => $request->string('concept')->toString(),
            'lesson' => $request->string('lesson')->toString(),
            'difficulty' => $request->string('difficulty')->toString(),
            'bloom' => $request->string('bloom')->toString(),
            'active' => $request->query('active'),
        ], self::EAGER, (int) $request->integer('per_page', 20));

        /*
         | ⚠️ WRAPPED BY HAND, because `JsonResource::withoutWrapping()` is on
         | globally. Without the wrapper this returns a BARE ARRAY — the client's
         | `response.data` is then `undefined`, `?? []` swallows it, and the bank
         | screen renders "no questions yet" against a full table with no error
         | anywhere.
         */
        return response()->json([
            'data' => BankQuestionResource::collection($questions->items()),
            'meta' => [
                'total' => $questions->total(),
                'current_page' => $questions->currentPage(),
                'last_page' => $questions->lastPage(),
            ],
        ]);
    }

    public function show(Question $question): JsonResponse
    {
        $this->authorize('view', $question);

        return response()->json([
            'data' => BankQuestionResource::make($question->loadMissing(self::EAGER)->loadCount('examItems')),
        ]);
    }

    public function store(SaveQuestionRequest $request, SaveQuestion $action): JsonResponse
    {
        $data = $this->data($request);

        // A super admin operating globally resolves to no workspace, and a bank
        // question has to belong to one teacher's bank. Answered rather than
        // defaulted: guessing here writes the row into whichever workspace the
        // fallback happened to pick.
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'تعذّر تحديد مكان عملك. أعد تحميل الصفحة.'], 422);
        }

        try {
            $question = $action->createInBank($workspaceId, $data->toAttributes(), $data->options ?? []);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(
            ['data' => BankQuestionResource::make($question->loadMissing(self::EAGER))],
            201
        );
    }

    public function update(SaveQuestionRequest $request, Question $question, SaveQuestion $action): JsonResponse
    {
        $data = $this->data($request);

        try {
            $question = $action->update($question, $data->toAttributes(), $data->options ?? []);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => BankQuestionResource::make($question->loadMissing(self::EAGER))]);
    }

    /**
     * FR-005: disabled when it has been sat, deleted when it has not.
     *
     * The route is `DELETE` and the ability is `disable`, deliberately. Naming
     * the ability after the verb would mean any future caller reaching for the
     * conventional `delete` ability gets a hard delete authorised for free.
     */
    public function destroy(Question $question, DisableQuestion $action): JsonResponse
    {
        $this->authorize('disable', $question);

        return response()->json(['deleted' => $action->handle($question)]);
    }

    /**
     * Validated payload into the DTO, with the two uuids resolved.
     *
     * Resolved through the models so `WorkspaceScope` answers — the validation
     * rule already scoped them, and this is the second lock on the same door.
     */
    private function data(SaveQuestionRequest $request): SaveQuestionData
    {
        $payload = $request->validated();

        $payload['concept_id'] = Concept::query()
            ->where('uuid', $request->string('concept_id')->toString())
            ->valueOrFail('id');

        $payload['lesson_id'] = $request->filled('lesson_id')
            ? Lesson::query()->where('uuid', $request->string('lesson_id')->toString())->value('id')
            : null;

        return SaveQuestionData::fromArray($payload);
    }
}
