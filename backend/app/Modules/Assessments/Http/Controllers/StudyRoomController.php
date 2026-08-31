<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\AnswerStudyRoomQuestion;
use App\Modules\Assessments\Actions\CreateStudyRoom;
use App\Modules\Assessments\Actions\JoinStudyRoom;
use App\Modules\Assessments\Actions\ListStudyRooms;
use App\Modules\Assessments\Actions\ReadStudyRoomBoard;
use App\Modules\Assessments\Data\StudyRoomDraftData;
use App\Modules\Assessments\Exceptions\AdaptiveConflictException;
use App\Modules\Assessments\Exceptions\FeatureDisabledException;
use App\Modules\Assessments\Exceptions\StudyRoomRefusal;
use App\Modules\Assessments\Http\Requests\AnswerStudyRoomRequest;
use App\Modules\Assessments\Http\Requests\CreateStudyRoomRequest;
use App\Modules\Assessments\Http\Resources\StudyRoomBoardResource;
use App\Modules\Assessments\Http\Resources\StudyRoomQuestionResource;
use App\Modules\Assessments\Http\Resources\StudyRoomResource;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\StudyRoom;
use App\Modules\Assessments\Models\StudyRoomParticipant;
use App\Modules\Assessments\Support\StudyRoomAccess;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group study rooms (spec 012 · US3).
 *
 * ⚠️ THE CATCH ORDER IS LOAD-BEARING, exactly as `AdaptiveController`'s docblock
 * records. `FeatureDisabledException`, `StudyRoomRefusal` and
 * `AdaptiveConflictException` all extend `DomainException`, so an arm for the
 * parent placed first swallows every one of them and turns a 403, a 409 and a 422
 * into one indistinguishable answer.
 *
 * ⚠️ AND `RuntimeException` IS NOT CAUGHT HERE, against this module's usual
 * habit. `ModelNotFoundException` EXTENDS `RuntimeException`, so an arm for it
 * would answer 422 to «this room does not exist» and «that question was never put
 * to you» — turning two deliberate 404s into a bad-request message that says which
 * ids do exist.
 *
 * ⚠️ AND NO ROUTE HERE BINDS A MODEL. `WorkspaceScope` adds no condition when the
 * context is null and it is null for every student, so an implicit `{room}` would
 * resolve any room on the platform — and no policy behind it could save that,
 * because a student holds no role in any workspace either. Every uuid is resolved
 * inside an Action, after the guard.
 */
class StudyRoomController extends Controller
{
    public function __construct(private readonly StudyRoomAccess $access) {}

    public function index(Request $request, ListStudyRooms $action): JsonResponse
    {
        $page = $action->handle($this->currentUser($request), (int) $request->integer('per_page', 20));

        return StudyRoomResource::collection($page)->response();
    }

    public function store(CreateStudyRoomRequest $request, CreateStudyRoom $action): JsonResponse
    {
        try {
            $result = $action->handle(
                $this->currentUser($request),
                StudyRoomDraftData::fromArray($request->validated()),
            );
        } catch (FeatureDisabledException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'feature_off'], 403);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $room = $result['room']->load('host:id,uuid,first_name,last_name', 'concept:id,uuid,name');

        return response()->json([
            // ⚠️ `requested_count` beside `question_count`: a short paper is an
            // answer, and an answer nobody is told is a room the student did not
            // ask for with no reason given (FR-023).
            'data' => StudyRoomResource::make($room, $result['requested']),
        ], 201);
    }

    public function join(Request $request, string $room, JoinStudyRoom $action): JsonResponse
    {
        try {
            $result = $action->handle($this->currentUser($request), $room);
        } catch (StudyRoomRefusal $refusal) {
            return $this->refuse($refusal);
        }

        $model = $result['room']->load('host:id,uuid,first_name,last_name', 'concept:id,uuid,name');
        $model->setRelation('viewerParticipation', $result['participant']);

        return response()->json([
            'data' => StudyRoomResource::make($model),
            'resumed' => $result['resumed'],
        ]);
    }

    /**
     * The room, its paper, the board, and where this participant got to.
     *
     * ⚠️ THIS IS THE ONLY SURFACE THAT HANDS OVER THE QUESTIONS, so the resume
     * (FR-015) is one read of it: the items come from the participant's own
     * attempt and each carries its answer row when there is one. A composed read
     * rather than an Action, because nothing here decides anything — every guard
     * it applies is `StudyRoomAccess`, asked once.
     */
    public function show(Request $request, string $room, ReadStudyRoomBoard $board): JsonResponse
    {
        $reader = $this->currentUser($request);
        $model = $this->access->room($room);

        if ($model === null) {
            throw (new ModelNotFoundException)->setModel(StudyRoom::class);
        }

        $participant = $this->access->participant($reader, $model);

        // The host who has not joined may still look in on their own room; a
        // stranger holding the uuid may not, and is answered the same refusal
        // FR-017 gives everywhere else.
        if ($participant === null && (int) $model->host_user_id !== (int) $reader->getKey()) {
            return $this->refuse(StudyRoomRefusal::notEligible());
        }

        $model->load('host:id,uuid,first_name,last_name', 'concept:id,uuid,name');
        $model->setRelation('viewerParticipation', $participant);

        return response()->json([
            'data' => [
                'room' => StudyRoomResource::make($model),
                'board' => StudyRoomBoardResource::make($model, $board->handle($model)),
                'questions' => $participant === null ? [] : $this->paper($participant),
            ],
        ]);
    }

    public function answer(
        AnswerStudyRoomRequest $request,
        string $room,
        AnswerStudyRoomQuestion $action,
    ): JsonResponse {
        /** @var list<int> $optionIds */
        $optionIds = array_map('intval', (array) $request->validated('option_ids'));

        try {
            $step = $action->handle(
                $this->currentUser($request),
                $room,
                (int) $request->validated('question_id'),
                $optionIds,
            );
        } catch (StudyRoomRefusal $refusal) {
            return $this->refuse($refusal);
        } catch (AdaptiveConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $model = $step['room'];
        $model->setRelation('viewerParticipation', $step['participant']);

        return response()->json([
            'data' => [
                // The mark scheme reaches the student HERE, one request after the
                // question was put to them.
                'result' => [
                    'is_correct' => $step['is_correct'],
                    'correct_option_ids' => $step['correct_option_ids'],
                    'explanation' => $step['explanation'],
                ],
                'room' => StudyRoomResource::make($model),
                'finished' => $step['finished'],
            ],
        ]);
    }

    public function board(Request $request, string $room, ReadStudyRoomBoard $action): JsonResponse
    {
        $model = $this->access->room($room);

        if ($model === null) {
            throw (new ModelNotFoundException)->setModel(StudyRoom::class);
        }

        // The board is authorised on PARTICIPATION, the same question the
        // broadcast channel asks — never on eligibility, or anyone holding the
        // uuid reads every name and score without joining and without appearing.
        if ($this->access->participant($this->currentUser($request), $model) === null) {
            return $this->refuse(StudyRoomRefusal::notEligible());
        }

        return response()->json(['data' => StudyRoomBoardResource::make($model, $action->handle($model))]);
    }

    /**
     * This participant's items, each with its answer row when it has one.
     *
     * ⚠️ TWO QUERIES FOR THE WHOLE PAPER, NEVER ONE PER ITEM. A Resource runs once
     * per row, so a lookup inside it is an N+1 by construction — thirty questions
     * on the page every participant opens first.
     *
     * @return list<StudyRoomQuestionResource>
     */
    private function paper(StudyRoomParticipant $participant): array
    {
        $items = AttemptItem::query()
            ->withoutWorkspaceScope()
            ->where('attempt_id', $participant->attempt_id)
            ->orderBy('order')
            ->get();

        $answers = Answer::query()
            ->withoutWorkspaceScope()
            ->where('attempt_id', $participant->attempt_id)
            ->get()
            ->keyBy('question_id');

        $out = [];

        foreach ($items as $item) {
            $out[] = new StudyRoomQuestionResource($item, $answers->get($item->question_id));
        }

        return $out;
    }

    private function refuse(StudyRoomRefusal $refusal): JsonResponse
    {
        return response()->json(
            ['message' => $refusal->getMessage(), 'code' => $refusal->refusalCode],
            $refusal->status,
        );
    }
}
