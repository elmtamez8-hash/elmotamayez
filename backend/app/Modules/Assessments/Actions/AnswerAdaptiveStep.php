<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Data\AdaptiveAnswerData;
use App\Modules\Assessments\Enums\AdaptiveStatus;
use App\Modules\Assessments\Enums\Difficulty;
use App\Modules\Assessments\Events\ConceptMastered;
use App\Modules\Assessments\Exceptions\AdaptiveConflictException;
use App\Modules\Assessments\Models\AdaptiveSession;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\ConceptMastery;
use App\Modules\Assessments\Support\AdaptiveLadder;
use App\Modules\Assessments\Support\AdaptiveSeal;
use App\Modules\Assessments\Support\AdaptiveSettings;
use App\Modules\Assessments\Support\AnswerMarker;
use App\Modules\Assessments\Support\PracticePaper;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Mark one answer, move the ladder, and serve what comes next (FR-002 · FR-003).
 *
 * @phpstan-type AdaptiveStep array{
 *     session: AdaptiveSession,
 *     is_correct: bool,
 *     correct_option_ids: list<int>,
 *     explanation: string|null,
 *     item: AttemptItem|null,
 *     difficulty_changed: bool,
 *     difficulty_note: string|null,
 * }
 *
 * ⚠️ THE COUNTERS MOVE ONLY AFTER THE ANSWER ROW IS WRITTEN. The insert is the
 * point of serialisation — `unique(attempt_id, question_id)` is what makes a
 * double tap a refusal rather than a second scored answer — so a streak advanced
 * before it would be advanced again by the tap that gets refused.
 */
class AnswerAdaptiveStep extends Action
{
    public function __construct(
        private readonly AnswerMarker $marker,
        private readonly AdaptiveLadder $ladder,
        private readonly AdaptiveSettings $settings,
        private readonly PracticePaper $paper,
        private readonly AdaptiveSeal $seal,
    ) {}

    /**
     * @return AdaptiveStep
     *
     * @throws ModelNotFoundException when the session is not this student's
     * @throws AdaptiveConflictException when this question already carries an answer
     * @throws DomainException when the session is already closed
     */
    public function handle(User $student, string $sessionUuid, AdaptiveAnswerData $data): array
    {
        $session = $this->sessionFor($student, $sessionUuid);

        if (! $session->status->isOpen()) {
            throw new DomainException('انتهت هذه الجلسة.');
        }

        $item = AttemptItem::query()
            ->withoutWorkspaceScope()
            ->where('attempt_id', $session->attempt_id)
            ->where('question_id', $data->questionId)
            ->first();

        /*
        | ⚠️ 404 AND NOT 422. A question id that was never served to this session
        | is not a bad request, it is a question about somebody else's paper —
        | and answering «that is not in your session» for an id that exists
        | elsewhere is a probe that says which ids do.
        */
        if ($item === null) {
            throw (new ModelNotFoundException)->setModel(AttemptItem::class);
        }

        $previouslyWrong = $this->marker->previouslyWrongQuestionIds($session->paper(), [$data->questionId]);

        try {
            $correct = $this->marker->mark($session->paper(), $item, $data->optionIds, null, $previouslyWrong);
        } catch (AdaptiveConflictException $exception) {
            throw $exception;
        } catch (DomainException $exception) {
            // The marker's only DomainException is the duplicate, and the
            // adaptive route answers that with 409 rather than 422.
            throw new AdaptiveConflictException($exception->getMessage(), 0, $exception);
        }

        return $this->advance($session, $item, $correct);
    }

    /**
     * @return AdaptiveStep
     */
    private function advance(AdaptiveSession $session, AttemptItem $item, bool $correct): array
    {
        $ceiling = $session->ceiling_difficulty;
        $answeredAt = $session->current_difficulty;
        $streak = $correct ? $session->correct_streak + 1 : 0;

        $snapshot = $item->snapshot;

        /*
        | The marked answer, built once and handed to whichever ending follows.
        | Assembled as a closure rather than unioned onto each branch's array with
        | `+`: the union erases the shape, and the shape is what the controller
        | reads key by key.
        */
        $marked = fn (AdaptiveSession $ended, ?AttemptItem $next, bool $changed, ?string $note): array => [
            'is_correct' => $correct,
            'correct_option_ids' => $item->correctOptionIds(),
            // Optional on the bank, so the screen renders without it rather than
            // going blank on every question a teacher imported in bulk.
            'explanation' => is_string($snapshot['explanation'] ?? null) ? $snapshot['explanation'] : null,
            'session' => $ended,
            'item' => $next,
            'difficulty_changed' => $changed,
            'difficulty_note' => $note,
        ];

        /*
        | ⚠️ MASTERY IS MEASURED AT THE CEILING, AND THE CEILING IS THIS CONCEPT'S
        | OWN. A concept whose questions are all `easy` has a ceiling of `easy`
        | and is mastered there. Compared against `hard` literally it could never
        | be mastered by any action the student can take — no row, no points, no
        | error anywhere — which is the family of defect this repository records
        | as «an item that enters the denominator and can never be completed».
        */
        if ($correct && $answeredAt->rank() >= $ceiling->rank() && $streak >= $this->settings->masteryCorrect()) {
            return $marked($this->master($session, $streak, $ceiling), null, false, null);
        }

        $wanted = $this->nextDifficulty($answeredAt, $ceiling, $correct, $streak);

        if ($wanted !== $answeredAt) {
            // Reset on every change of level: carried across two levels it is a
            // sum, not mastery of one.
            $streak = 0;
        }

        if ($session->served_count >= $this->settings->maxQuestions()) {
            return $marked($this->close($session), null, false, 'بلغتَ نهاية الجلسة. ابدأ جلسةً جديدةً لمواصلة التمرين.');
        }

        $question = $this->ladder->next(
            (int) $session->workspace_id,
            $session->learner(),
            (int) $session->concept_id,
            $wanted,
            (int) $session->attempt_id,
            // Which way the ladder was heading, so an equidistant walk lands on
            // the side the student earned rather than always falling back.
            $wanted->rank() <=> $answeredAt->rank(),
        );

        if ($question === null) {
            return $marked($this->close($session), null, false, 'انتهت أسئلة هذه الفكرة المتاحة لك.');
        }

        $served = AdaptiveLadder::difficultyOf($question);

        if ($served !== $wanted) {
            // FR-004: the walk to the nearest available level is announced. A
            // silent one is a student handed a difficulty they did not earn and
            // cannot account for.
            $streak = 0;
        }

        $next = $this->paper->append($session->paper(), $question);

        $session->increment('served_count', 1, [
            'current_difficulty' => $served->value,
            'correct_streak' => $streak,
        ]);

        return $marked($session->refresh(), $next, $served !== $answeredAt, $this->note($answeredAt, $served, $wanted));
    }

    /**
     * Where the ladder points after this answer, before availability is consulted.
     */
    private function nextDifficulty(Difficulty $current, Difficulty $ceiling, bool $correct, int $streak): Difficulty
    {
        if (! $correct) {
            return $current->easier() ?? $current;
        }

        if ($streak < $this->settings->promoteAfter()) {
            return $current;
        }

        $harder = $current->harder();

        // Never past the ceiling: above it there is nothing to ask.
        return $harder !== null && $harder->rank() <= $ceiling->rank() ? $harder : $current;
    }

    private function note(Difficulty $from, Difficulty $served, Difficulty $wanted): ?string
    {
        if ($served !== $wanted) {
            return 'لا أسئلة متاحة بهذه الصعوبة الآن، فانتقلنا إلى أقرب صعوبة في الفكرة نفسها.';
        }

        if ($served->rank() > $from->rank()) {
            return 'أحسنت — رفعنا الصعوبة.';
        }

        if ($served->rank() < $from->rank()) {
            return 'نزلنا لسؤال أسهل في الفكرة نفسها.';
        }

        return null;
    }

    /**
     * Write the mastery row, close the session and announce it.
     */
    private function master(AdaptiveSession $session, int $streak, Difficulty $ceiling): AdaptiveSession
    {
        $threshold = $this->settings->masteryCorrect();

        /*
        | ⚠️ `firstOrCreate` ON THE UNIQUE PAIR, AND THE THRESHOLD IS FROZEN INTO
        | THE ROW. One row per (student, concept) is what makes the award
        | idempotent; the two `threshold_*` columns are what let a reader a year
        | later say on WHAT criterion it was granted, after somebody has edited
        | the setting.
        */
        $mastery = ConceptMastery::query()->firstOrCreate(
            [
                'student_user_id' => $session->student_user_id,
                'concept_id' => $session->concept_id,
            ],
            [
                'workspace_id' => $session->workspace_id,
                'mastered_at' => now(),
                'threshold_correct' => $threshold,
                'threshold_difficulty' => $ceiling->value,
                'source_session_id' => $session->getKey(),
            ],
        );

        $session->update(['correct_streak' => $streak]);

        /*
        | ⚠️ THE EVENT FIRES ONLY FOR THE CALL THAT ACTUALLY CLOSED THE SESSION.
        | `claimClosure()` is a conditional UPDATE, so two requests arriving
        | together produce one true and one false — and awarding on the false one
        | would pay a second time for one mastery.
        */
        if ($session->claimClosure(AdaptiveStatus::Mastered)) {
            $this->seal->seal($session);

            event(new ConceptMastered(
                (int) $session->student_user_id,
                (int) $session->concept_id,
                (int) $session->workspace_id,
                (int) $mastery->getKey(),
            ));
        }

        return $session->refresh();
    }

    private function close(AdaptiveSession $session): AdaptiveSession
    {
        if ($session->claimClosure(AdaptiveStatus::Ended)) {
            $this->seal->seal($session);
        }

        return $session->refresh();
    }

    private function sessionFor(User $student, string $uuid): AdaptiveSession
    {
        /** @var AdaptiveSession */
        return AdaptiveSession::query()
            ->withoutWorkspaceScope()
            // ⚠️ THE OWNERSHIP CONDITION IS THE WHOLE GUARD. `WorkspaceScope` adds
            // nothing for a student, so without this line any uuid resolves.
            ->where('student_user_id', $student->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
