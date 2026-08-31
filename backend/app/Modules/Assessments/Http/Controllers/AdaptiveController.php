<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\AnswerAdaptiveStep;
use App\Modules\Assessments\Actions\EndAdaptiveSession;
use App\Modules\Assessments\Actions\ListAdaptiveConcepts;
use App\Modules\Assessments\Actions\StartAdaptiveSession;
use App\Modules\Assessments\Data\AdaptiveAnswerData;
use App\Modules\Assessments\Data\AdaptiveStartData;
use App\Modules\Assessments\Exceptions\AdaptiveConflictException;
use App\Modules\Assessments\Exceptions\FeatureDisabledException;
use App\Modules\Assessments\Http\Requests\AnswerAdaptiveRequest;
use App\Modules\Assessments\Http\Requests\StartAdaptiveRequest;
use App\Modules\Assessments\Http\Resources\AdaptiveConceptResource;
use App\Modules\Assessments\Http\Resources\AdaptiveQuestionResource;
use App\Modules\Assessments\Http\Resources\AdaptiveSessionResource;
use App\Modules\Assessments\Models\AttemptItem;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The adaptive practice path (spec 012 · US1).
 *
 * ⚠️ THE CATCH ORDER IS LOAD-BEARING. `FeatureDisabledException` and
 * `AdaptiveConflictException` both extend `DomainException`, so caught after it
 * they never run and every refusal becomes 422 — a conflict indistinguishable
 * from a bad request, and «the teacher has not switched this on» read as «try
 * another concept».
 *
 * ⚠️ AND `RuntimeException` IS DELIBERATELY NOT CAUGHT HERE, against this
 * module's usual habit of catching both hierarchies. `ModelNotFoundException`
 * EXTENDS `RuntimeException`, so a `RuntimeException` arm swallows every
 * `firstOrFail()` in these Actions and answers 422 to «this session is not
 * yours» and «that question was never served to you» — turning two deliberate
 * 404s into a bad-request message that says which ids exist. `AnswerMarker`'s
 * own `RuntimeException` is a genuine fault (a write the database swallowed) and
 * belongs in a 500, not in a sentence for a student. Every refusal these Actions
 * raise on purpose is a `DomainException`.
 */
class AdaptiveController extends Controller
{
    public function store(StartAdaptiveRequest $request, StartAdaptiveSession $action): JsonResponse
    {
        $data = AdaptiveStartData::fromArray($request->validated());
        $student = $this->currentUser($request);

        try {
            return $this->session($action->handle($student, $data), 201);
        } catch (FeatureDisabledException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'feature_off'], 403);
        } catch (AdaptiveConflictException $exception) {
            /*
             | ⚠️ THE 409 CARRIES THE SESSION, NOT JUST A SENTENCE. «You already
             | have one open» is only useful with the one — there is no separate
             | route to fetch it by, so a client that reloaded mid-session would
             | otherwise be told it may not start and given no way back in.
             */
            $resumed = $action->resume($student, $data);

            return $resumed === null
                ? response()->json(['message' => $exception->getMessage()], 409)
                : $this->session($resumed, 409);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function answer(
        AnswerAdaptiveRequest $request,
        string $session,
        AnswerAdaptiveStep $action,
    ): JsonResponse {
        try {
            $step = $action->handle(
                $this->currentUser($request),
                $session,
                AdaptiveAnswerData::fromArray($request->validated()),
            );
        } catch (AdaptiveConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        /** @var AttemptItem|null $item */
        $item = $step['item'];

        return response()->json([
            'data' => [
                // The correct answer and the explanation reach the student HERE
                // and one request earlier would have been the mark scheme.
                'result' => [
                    'is_correct' => $step['is_correct'],
                    'correct_option_ids' => $step['correct_option_ids'],
                    'explanation' => $step['explanation'],
                ],
                'session' => AdaptiveSessionResource::make($step['session']),
                'difficulty_changed' => $step['difficulty_changed'],
                'difficulty_note' => $step['difficulty_note'],
                'question' => $item === null ? null : AdaptiveQuestionResource::make($item),
            ],
        ]);
    }

    public function end(Request $request, string $session, EndAdaptiveSession $action): JsonResponse
    {
        return response()->json([
            'data' => ['session' => AdaptiveSessionResource::make(
                $action->handle($this->currentUser($request), $session),
            )],
        ]);
    }

    /**
     * ⚠️ AN EMPTY LIST, NEVER A 403. There is no `teacher` parameter here, so
     * «is the switch on» has one answer per teacher — the Action filters, and a
     * student with no teacher running the feature sees a screen that explains
     * itself rather than a refusal about a page that is not an error.
     */
    public function concepts(Request $request, ListAdaptiveConcepts $action): JsonResponse
    {
        return response()->json([
            'data' => AdaptiveConceptResource::collection($action->handle($this->currentUser($request))),
        ]);
    }

    /**
     * @param  array{session: mixed, item: mixed, resumed: bool}  $result
     */
    private function session(array $result, int $status): JsonResponse
    {
        /** @var AttemptItem|null $item */
        $item = $result['item'];

        return response()->json([
            'data' => [
                'session' => AdaptiveSessionResource::make($result['session']),
                'resumed' => $result['resumed'],
                'question' => $item === null ? null : AdaptiveQuestionResource::make($item),
            ],
        ], $status);
    }
}
