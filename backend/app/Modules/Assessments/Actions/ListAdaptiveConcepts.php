<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Enums\Difficulty;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\ConceptMastery;
use App\Modules\Assessments\Support\AdaptiveLadder;
use App\Modules\Assessments\Support\PracticePool;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Flags;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;

/**
 * What this student may practise adaptively, and how far they have got.
 *
 * ⚠️ THE LIST IS DERIVED FROM `PracticePool` — THE SAME QUERY THE SESSION DRAWS
 * FROM. A list assembled beside it offers a concept the start endpoint then
 * refuses with 422, and hides one it would have accepted: the defect spec 009's
 * leaderboard picker paid for, where the screen and the door answered two
 * different questions.
 *
 * ⚠️ ONE GROUPED QUERY PER TEACHER, NEVER ONE PER CONCEPT. The obvious shape —
 * ask the pool how many questions each concept has — is an unbounded N+1 in which
 * every iteration rebuilds the whole withheld set. Per teacher is the floor: the
 * pool is scoped to one bank by construction, so a single cross-teacher query
 * would need a second spelling of the pool.
 *
 * ⚠️ AND THE FEATURE SWITCH FILTERS HERE, IT DOES NOT REFUSE. There is no
 * `teacher` parameter, so «is the flag on?» has one answer per teacher; an empty
 * list is a STATE the screen explains, while a 403 is «the feature is off» said
 * about a page that is not an error.
 */
class ListAdaptiveConcepts extends Action
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly PracticePool $pool,
        private readonly Flags $flags,
    ) {}

    /**
     * @return list<array{
     *     uuid: string, name: string, question_count: int,
     *     ceiling_difficulty: string, mastered_at: string|null,
     *     teacher: array{uuid: string, name: string},
     * }>
     */
    public function handle(User $student): array
    {
        $workspaceIds = array_values(array_filter(
            $this->enrollments->activeWorkspaceIdsFor($student),
            fn (int $id): bool => $this->flags->enabled(StartAdaptiveSession::FLAG, $id),
        ));

        if ($workspaceIds === []) {
            return [];
        }

        $teachers = Workspace::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $workspaceIds)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name']);

        $out = [];
        $conceptIds = [];
        $grouped = [];

        foreach ($teachers as $teacher) {
            /*
            | ⚠️ `MAX()` OVER THE RANK EXPRESSION, NOT OVER THE COLUMN. `difficulty`
            | is a string and alphabetically `easy < hard < medium`, so a plain
            | `MAX(difficulty)` reports `medium` as the ceiling of a concept that
            | holds hard questions — and the screen would then ask the student to
            | reach a level the session measures somewhere else.
            */
            $rows = $this->pool->questionsFor((int) $teacher->id, $student)
                ->whereNotNull('concept_id')
                ->toBase()
                ->selectRaw('concept_id, count(*) as question_count, max('.AdaptiveLadder::RANK_SQL.') as ceiling_rank')
                ->groupBy('concept_id')
                ->get();

            foreach ($rows as $row) {
                $conceptIds[] = (int) $row->concept_id;
            }

            $grouped[(int) $teacher->id] = $rows;
        }

        if ($conceptIds === []) {
            return [];
        }

        $concepts = Concept::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $conceptIds)
            ->get(['id', 'uuid', 'name'])
            ->keyBy('id');

        // One query for every mastery on the list, never one per concept.
        $mastered = ConceptMastery::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())
            ->whereIn('concept_id', $conceptIds)
            ->get(['concept_id', 'mastered_at'])
            ->keyBy('concept_id');

        foreach ($teachers as $teacher) {
            foreach ($grouped[(int) $teacher->id] as $row) {
                $concept = $concepts->get((int) $row->concept_id);

                if ($concept === null) {
                    continue;
                }

                $out[] = [
                    'uuid' => (string) $concept->uuid,
                    'name' => (string) $concept->name,
                    'teacher' => [
                        // A teacher IS a workspace here, so the name is the
                        // workspace's — `users` has no `name` column at all.
                        'uuid' => (string) $teacher->uuid,
                        'name' => (string) $teacher->name,
                    ],
                    'question_count' => (int) $row->question_count,
                    'ceiling_difficulty' => self::rankToDifficulty((int) $row->ceiling_rank)->value,
                    'mastered_at' => $mastered->get((int) $row->concept_id)?->mastered_at?->toIso8601String(),
                ];
            }
        }

        return $out;
    }

    /** The inverse of {@see AdaptiveLadder::RANK_SQL}, in one place. */
    private static function rankToDifficulty(int $rank): Difficulty
    {
        foreach (Difficulty::cases() as $case) {
            if ($case->rank() === $rank) {
                return $case;
            }
        }

        return Difficulty::Easy;
    }
}
