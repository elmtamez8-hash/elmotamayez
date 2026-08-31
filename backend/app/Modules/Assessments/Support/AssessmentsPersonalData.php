<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\AdaptiveSession;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\ConceptMastery;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

/**
 * Assessments's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class AssessmentsPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'assessments';
    }

    /** @return list<string> */
    public function describe(): array
    {
        /*
        | ⚠️ SPEC 012'S TWO TABLES ARE HERE, AND NOTHING WOULD HAVE TOLD ME IF
        | THEY WERE NOT. `PersonalDataContractCoverageTest` is a per-MODULE guard
        | — its own docblock says so — so a NEW table inside an ALREADY registered
        | module is invisible to it. The category row, the export, the erasure and
        | the expiry all have to be added in the same change, by hand.
        */
        return ['exam_attempt', 'exam_answer', 'adaptive_session', 'concept_mastery'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        /*
        | ⚠️ GATED ON `Results`, AND THE GATE IS HERE RATHER THAN AT THE DOOR. A
        | guardian granted "attendance" alone opened the request legitimately — the
        | permission limits the CONTENT, not merely the entrance. Without this
        | branch an attendance-only guardian receives every mark their child ever
        | got, which is the exact case `GuardianScopeTest` measures.
        */
        if (! $subject->mayReceive(GuardianPermission::Results)) {
            yield from ExportWalk::none(...$this->describe());

            return;
        }

        $userId = $subject->user->getKey();

        yield from ExportWalk::keyed(
            'exam_attempt',
            Attempt::query()
                ->withoutWorkspaceScope()
                ->leftJoin('exams', 'exams.id', '=', 'exam_attempts.exam_id')
                ->where('exam_attempts.student_user_id', $userId)
                ->select(['exam_attempts.*', 'exams.title as exam_title']),
            fn (Attempt $attempt): array => [
                'uuid' => $attempt->uuid,
                'exam_title' => $attempt->getAttribute('exam_title'),
                'status' => $attempt->status,
                'score' => $attempt->score,
                'max_score' => $attempt->max_score,
                'passed' => $attempt->passed,
                'is_practice' => $attempt->is_practice,
                'started_at' => ExportWalk::at($attempt->started_at),
                'submitted_at' => ExportWalk::at($attempt->submitted_at),
            ],
            column: 'exam_attempts.id',
        );

        /*
        | ⚠️ THE QUESTION TEXT COMES FROM THE SNAPSHOT, NEVER FROM `questions`.
        | Grading reads the frozen copy, so an exam edited after this person sat it
        | has a live row that is not what they were asked — showing them the current
        | wording beside a mark earned against the old one is a false record of
        | their own paper.
        |
        | ⚠️ AND THE WHOLE SNAPSHOT IS NOT EXPORTED, only its `content`. This is the
        | heaviest personal table in the product — one row per item per attempt,
        | each carrying a JSON copy of a question and all its options — and it is
        | also the one place where a full dump would hand over `correct_option_ids`
        | for every question in a live bank. The mark, the question and the answer
        | are the person's record; the answer key is the teacher's.
        */
        yield from ExportWalk::keyed(
            'exam_answer',
            Answer::query()
                ->withoutWorkspaceScope()
                ->leftJoin('attempt_items', function (JoinClause $join): void {
                    $join->on('attempt_items.attempt_id', '=', 'exam_answers.attempt_id')
                        ->on('attempt_items.question_id', '=', 'exam_answers.question_id');
                })
                ->where('exam_answers.student_user_id', $userId)
                ->select(['exam_answers.*', 'attempt_items.snapshot as item_snapshot']),
            function (Answer $answer): array {
                /** @var array<string, mixed> $snapshot */
                $snapshot = json_decode((string) $answer->getAttribute('item_snapshot'), true) ?: [];

                return [
                    'uuid' => $answer->uuid,
                    'question' => $snapshot['content'] ?? null,
                    'answer_text' => $answer->answer_text,
                    'is_correct' => $answer->is_correct,
                    'points' => $answer->points,
                    'requires_grading' => $answer->requires_grading,
                    'graded_at' => ExportWalk::at($answer->graded_at),
                    'answered_at' => ExportWalk::at($answer->created_at),
                ];
            },
            column: 'exam_answers.id',
        );

        /*
        | Spec 012. The session is the FRAME — how far the ladder went and where
        | it stopped; the answers underneath it are already exported above, under
        | `exam_answer`, because every adaptive answer is a row in `exam_answers`.
        | Exporting the questions again here would hand the same person the same
        | text twice under two headings.
        */
        yield from ExportWalk::keyed(
            'adaptive_session',
            AdaptiveSession::query()
                ->withoutWorkspaceScope()
                ->leftJoin('concepts', 'concepts.id', '=', 'adaptive_sessions.concept_id')
                ->where('adaptive_sessions.student_user_id', $userId)
                ->select(['adaptive_sessions.*', 'concepts.name as concept_name']),
            fn (AdaptiveSession $session): array => [
                'uuid' => $session->uuid,
                'concept' => $session->getAttribute('concept_name'),
                'status' => $session->status->value,
                'reached_difficulty' => $session->current_difficulty->value,
                'ceiling_difficulty' => $session->ceiling_difficulty->value,
                'questions_served' => $session->served_count,
                'started_at' => ExportWalk::at($session->created_at),
                'mastered_at' => ExportWalk::at($session->mastered_at),
                'ended_at' => ExportWalk::at($session->ended_at),
            ],
            column: 'adaptive_sessions.id',
        );

        /*
        | ⚠️ THE THRESHOLDS TRAVEL WITH THE ROW. «You mastered this» is a claim
        | about a person, and the only way they can check it is to be told what
        | the bar actually was on the day — which is exactly why the two columns
        | are stored rather than read live from a setting somebody has since
        | changed.
        */
        yield from ExportWalk::keyed(
            'concept_mastery',
            ConceptMastery::query()
                ->withoutWorkspaceScope()
                ->leftJoin('concepts', 'concepts.id', '=', 'concept_masteries.concept_id')
                ->where('concept_masteries.student_user_id', $userId)
                ->select(['concept_masteries.*', 'concepts.name as concept_name']),
            fn (ConceptMastery $mastery): array => [
                'uuid' => $mastery->uuid,
                'concept' => $mastery->getAttribute('concept_name'),
                'mastered_at' => ExportWalk::at($mastery->mastered_at),
                'threshold_correct' => $mastery->threshold_correct,
                'threshold_difficulty' => $mastery->threshold_difficulty->value,
            ],
            column: 'concept_masteries.id',
        );
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode !== ErasureMode::Delete) {
            return 0;
        }

        $userId = $subject->user->getKey();

        /*
        | ⚠️ THREE TABLES IN DEPENDENCY ORDER, AND `attempt_items` IS THE ONE THAT
        | HIDES. It carries no user column at all — one row per item per attempt,
        | reachable only through `attempt_id` — so a walk that deleted the attempts
        | first would leave every snapshot behind, each holding the question a named
        | person was asked, addressed by an id nothing resolves.
        |
        | `exam_answers` DOES carry `student_user_id` (added in 008), which is why
        | it is deleted by the person and the items by their attempts.
        */
        /*
        | ⚠️ SPEC 012'S TWO TABLES GO FIRST, AND `adaptive_sessions` IS THE ONE
        | THAT WOULD HIDE. It names an `attempt_id`, so deleting the attempts
        | below first leaves every session pointing at an id nothing resolves —
        | the same shape `attempt_items` has, and the reason that one is written
        | down. `concept_masteries` stands alone and is deleted for tidiness of
        | ordering, not necessity.
        */
        $adaptive = AdaptiveSession::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $userId)
            ->limit($limit)
            ->delete();

        if ($adaptive >= $limit) {
            return $adaptive;
        }

        $adaptive += ConceptMastery::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $userId)
            ->limit($limit - $adaptive)
            ->delete();

        if ($adaptive >= $limit) {
            return $adaptive;
        }

        $answers = $adaptive + Answer::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $userId)
            ->limit($limit - $adaptive)
            ->delete();

        if ($answers >= $limit) {
            return $answers;
        }

        $attemptIds = Attempt::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $userId)
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($attemptIds === []) {
            return $answers;
        }

        $items = AttemptItem::query()
            ->withoutWorkspaceScope()
            ->whereIn('attempt_id', $attemptIds)
            ->limit($limit)
            ->delete();

        if ($items >= $limit) {
            return $answers + $items;
        }

        // The attempts go last, and only for the ids whose items are now gone.
        return $answers + $items + Attempt::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $attemptIds)
            ->delete();
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     *
     * @param  list<int>  $exemptUserIds  subjects under a live hold — their rows stay.
     */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        if ($mode !== ExpiryBehaviour::Delete) {
            return 0;
        }

        /*
        | ⚠️ THE BOUND IS A DATE COMPUTED IN PHP AND COMPARED AS A STRING —
        | `whereDate()` wraps the column and throws away the index the migration
        | beside this file exists to provide, and `created_at + INTERVAL n DAY`
        | evaluated in SQL raises ERROR 1441 past year 9999 on MySQL while SQLite
        | silently returns NULL and expires nothing.
        */
        $cutoff = $before->toDateTimeString();

        if ($category === 'adaptive_session') {
            /*
            | ⚠️ THE SESSION ROW ONLY. Its answers and its items hang off the
            | ATTEMPT and expire under `exam_answer` and `exam_attempt` on their
            | own clocks — deleting them from here would apply this category's
            | retention to rows another category governs, which is the one thing
            | a per-category sweep must never do.
            */
            $sessions = AdaptiveSession::query()
                ->withoutWorkspaceScope()
                ->where('created_at', '<', $cutoff);

            if ($exemptUserIds !== []) {
                $sessions->whereNotIn('student_user_id', $exemptUserIds);
            }

            return $sessions->limit($limit)->delete();
        }

        if ($category === 'concept_mastery') {
            $masteries = ConceptMastery::query()
                ->withoutWorkspaceScope()
                ->where('created_at', '<', $cutoff);

            if ($exemptUserIds !== []) {
                $masteries->whereNotIn('student_user_id', $exemptUserIds);
            }

            return $masteries->limit($limit)->delete();
        }

        if ($category === 'exam_answer') {
            $answers = Answer::query()
                ->withoutWorkspaceScope()
                ->where('created_at', '<', $cutoff);

            if ($exemptUserIds !== []) {
                $answers->whereNotIn('student_user_id', $exemptUserIds);
            }

            return $answers->limit($limit)->delete();
        }

        if ($category !== 'exam_attempt') {
            return 0;
        }

        /*
        | ⚠️ `attempt_items` FIRST, AND IT IS THE TABLE THAT HIDES. It carries no
        | user column at all — one snapshot row per item per attempt, reachable
        | only through `attempt_id` — so deleting the attempts first strands every
        | snapshot of what a named person was asked behind an id nothing resolves.
        | The same ordering `erase()` uses, for the same reason.
        */
        $attemptIds = Attempt::query()
            ->withoutWorkspaceScope()
            ->where('created_at', '<', $cutoff)
            ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('student_user_id', $exemptUserIds))
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($attemptIds === []) {
            return 0;
        }

        /*
        | ⚠️ THE ANSWERS ARE CLEARED HERE TOO, AND NOT BECAUSE THEY ARE STILL THERE
        | IN THE SHIPPED CATALOGUE. They expire at 1095 days against this table's
        | 1825, so in the seeded configuration this deletes nothing — but the two
        | durations are operator-editable rows, and an operator who clears
        | `exam_answer`'s retention leaves rows whose foreign key would refuse the
        | DELETE below and kill the whole sweep on its first night.
        */
        $children = Answer::query()
            ->withoutWorkspaceScope()
            ->whereIn('attempt_id', $attemptIds)
            ->limit($limit)
            ->delete();

        if ($children >= $limit) {
            return $children;
        }

        $items = $children + AttemptItem::query()
            ->withoutWorkspaceScope()
            ->whereIn('attempt_id', $attemptIds)
            ->limit($limit - $children)
            ->delete();

        if ($items >= $limit) {
            return $items;
        }

        // The attempts go last, and only for the ids whose items are now gone.
        return $items + Attempt::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $attemptIds)
            ->delete();
    }
}
