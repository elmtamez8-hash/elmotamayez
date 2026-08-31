<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Data\StudyRoomDraftData;
use App\Modules\Assessments\Enums\Difficulty;
use App\Modules\Assessments\Exceptions\FeatureDisabledException;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\StudyRoom;
use App\Modules\Assessments\Models\StudyRoomQuestion;
use App\Modules\Assessments\Support\PracticePool;
use App\Modules\Assessments\Support\PracticeWorkspace;
use App\Modules\Assessments\Support\QuestionSnapshot;
use App\Modules\Tenancy\Support\Flags;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Open a study room and freeze its paper (spec 012 · FR-013).
 *
 * ⚠️ THE PAPER IS DRAWN FROM THE HOST'S OWN `PracticePool` AND FROZEN HERE, once.
 * «They solve THE SAME SET at the same time» is the story's own wording, and a
 * set chosen at each join is several papers wearing one room's name — the board
 * would then be comparing scores earned on different questions.
 *
 * ⚠️ AND A SHORT PAPER IS AN ANSWER, NOT A FAILURE. Asking for twenty questions
 * from a concept that holds six builds a room of six and reports both numbers;
 * FR-023 says it in as many words and `BuildSelfExam.php:86` writes it above the
 * same branch. The refusal is reserved for ZERO, where there is nothing to open.
 */
class CreateStudyRoom extends Action
{
    public const FLAG = 'study_rooms';

    /** FR-019's neighbour: the broadcast cost of a room is linear in both. */
    public const MAX_QUESTIONS = 30;

    public const MAX_PARTICIPANTS = 30;

    /**
     * ⚠️ `study_room_participants.score` IS AN `unsignedSmallInteger` HOLDING THE
     * SUM OVER AT MOST THIRTY QUESTIONS. Thirty times a hundred is 3000, well
     * inside 65535; a question worth a thousand points would overflow it — strict
     * MySQL refuses the row and SQLite truncates in silence, so no local test
     * could ever see it.
     */
    public const MAX_POINTS = 100;

    public function __construct(
        private readonly PracticeWorkspace $workspaces,
        private readonly PracticePool $pool,
        private readonly Flags $flags,
    ) {}

    /**
     * @return array{room: StudyRoom, requested: int, delivered: int}
     *
     * @throws FeatureDisabledException when this teacher has study rooms switched off
     * @throws DomainException when the teacher, the concept, or the paper is empty
     */
    public function handle(User $host, StudyRoomDraftData $data): array
    {
        $workspaceId = $this->workspaces->resolve($host, $data->teacherUuid);

        if ($workspaceId === null) {
            throw new DomainException('اختر مدرّساً تدرس عنده الآن.');
        }

        /*
        | ⚠️ READ WITH THIS ID, NEVER FROM `WorkspaceContext`. That is null for
        | every student and `(int) null === 0` addresses the PLATFORM default row,
        | which ships OFF — so the feature would be refused to everybody while the
        | panel showed it switched on for the teacher.
        */
        if (! $this->flags->enabled(self::FLAG, $workspaceId)) {
            throw new FeatureDisabledException('غرف المذاكرة غير مفعَّلة عند هذا المدرّس.');
        }

        $conceptId = null;

        if ($data->conceptUuid !== null) {
            $conceptId = (int) Concept::query()
                ->withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('uuid', $data->conceptUuid)
                ->value('id');

            // Zero means the uuid names nothing in THIS bank. Falling through to
            // the unfiltered pool would silently build a different room.
            if ($conceptId === 0) {
                throw new DomainException('لا توجد هذه الفكرة عند هذا المدرّس.');
            }
        }

        $requested = max(1, min(self::MAX_QUESTIONS, $data->questionCount));
        $questions = $this->draw($workspaceId, $host, $conceptId, $data->difficulty, $requested);

        if ($questions === []) {
            throw new DomainException('لا أسئلة متاحة لك بهذه المواصفات الآن.');
        }

        $startsAt = now()->addMinutes(max(0, min(60, $data->startsInMinutes)));
        $duration = max(1, min(180, $data->durationMinutes));

        $room = StudyRoom::create([
            'workspace_id' => $workspaceId,
            'host_user_id' => $host->getKey(),
            'concept_id' => $conceptId,
            'difficulty' => $data->difficulty,
            // What was DELIVERED. `StudyRoomAccess::mayJoin()` compares against
            // this column, so it must never hold what was merely asked for.
            'question_count' => count($questions),
            'max_participants' => max(1, min(self::MAX_PARTICIPANTS, $data->maxParticipants)),
            'duration_minutes' => $duration,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($duration),
        ]);

        $this->freeze($room, $questions);

        return ['room' => $room, 'requested' => $requested, 'delivered' => count($questions)];
    }

    /**
     * The host's own pool, narrowed and shuffled.
     *
     * ⚠️ `PracticePool`, NEVER `questions` DIRECTLY. It subtracts the exams this
     * student has not sat yet — and a room is the fastest way to hand those out,
     * because every answer inside one is marked on the spot with its explanation.
     *
     * @return list<Question>
     */
    private function draw(int $workspaceId, User $host, ?int $conceptId, ?string $difficulty, int $count): array
    {
        $query = $this->pool->questionsFor($workspaceId, $host)->with('options');

        if ($conceptId !== null) {
            $query->where('concept_id', $conceptId);
        }

        // `tryFrom`, so a value the client invented narrows to nothing rather
        // than reaching the column raw.
        if ($difficulty !== null && Difficulty::tryFrom($difficulty) !== null) {
            $query->where('difficulty', $difficulty);
        }

        return array_values($query->inRandomOrder()->limit($count)->get()->all());
    }

    /**
     * Write the frozen paper in one statement.
     *
     * ⚠️ `created_at` AND `updated_at` ARE PASSED BY HAND. `insert()` boots no
     * model, so the timestamps are not filled for us — and both columns accept
     * NULL on either engine without a word. Retention sweeps by age, so rows with
     * a null `created_at` match no age predicate and NEVER EXPIRE. The precedent
     * is `CreditLedger::writeEntry()`, which passes its `uuid` and `created_at`
     * for the same reason.
     *
     * @param  list<Question>  $questions
     */
    private function freeze(StudyRoom $room, array $questions): void
    {
        $now = now();
        $rows = [];

        foreach ($questions as $index => $question) {
            $rows[] = [
                'workspace_id' => $room->workspace_id,
                'study_room_id' => $room->getKey(),
                'question_id' => $question->getKey(),
                'order' => $index + 1,
                'points' => max(1, min(self::MAX_POINTS, (int) $question->points)),
                'snapshot' => json_encode(QuestionSnapshot::of($question)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(fn () => StudyRoomQuestion::query()->insert($rows));
    }
}
