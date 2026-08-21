<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
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
        return ['exam_attempt', 'exam_answer'];
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
        // TODO(013-US4): erase or anonymise this module's rows for the subject.
        return 0;
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
