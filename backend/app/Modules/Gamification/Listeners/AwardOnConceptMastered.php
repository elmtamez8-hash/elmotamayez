<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Assessments\Events\ConceptMastered;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A concept mastered on the adaptive path ⇒ points and coins (spec 012 · FR-003).
 *
 * ⚠️ THE SOURCE IS THE CONCEPT, NOT THE SESSION. Mastery of a concept happens
 * once — `concept_masteries` carries `unique(student_user_id, concept_id)` and
 * `AnswerAdaptiveStep` uses `firstOrCreate` over it — so keying the award on the
 * session would pay again for every later session that reached the same bar. It
 * is the same reasoning `AwardOnMistakeResolved` uses for keying on the question
 * rather than the answer row.
 *
 * Coins are real here, unlike `focus_session` and `invite_friend`: mastery
 * happens inside ONE teacher's bank, so there is a workspace to hold them —
 * `AwardPoints` throws on a coin-bearing action with a null workspace rather
 * than guessing a purse.
 */
class AwardOnConceptMastered implements ShouldQueue
{
    public function __construct(private readonly AwardPoints $award) {}

    public function handle(ConceptMastered $event): void
    {
        $this->award->handle(new AwardRequest(
            studentUserId: $event->studentId,
            actionKey: 'concept_mastered',
            sourceType: 'concept',
            sourceId: $event->conceptId,
            workspaceId: $event->workspaceId,
        ));
    }
}
