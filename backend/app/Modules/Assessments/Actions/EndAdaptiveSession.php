<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Enums\AdaptiveStatus;
use App\Modules\Assessments\Models\AdaptiveSession;
use App\Modules\Assessments\Support\AdaptiveSeal;
use App\Shared\Actions\Action;

/**
 * The student stops (spec 012 · FR-001).
 *
 * ⚠️ IT SEALS THE ATTEMPT ITSELF AND DOES NOT GO THROUGH `GradeAttempt`. That
 * action writes an answer row for EVERY item on the paper — which is right for a
 * paper handed in whole and a guaranteed collision here, where every question was
 * already marked as it was answered.
 *
 * ⚠️ AND IT RAISES NO `AttemptFinalized`. That event's notification listener
 * fires for practice attempts DELIBERATELY, so every adaptive session would have
 * produced a «نتيجة اختبار» message about a revision run the student was in the
 * middle of. The only event this feature raises is `ConceptMastered`, and only
 * when a concept was actually mastered.
 *
 * A terminal session returns cleanly rather than refusing: «stop» pressed twice
 * is one intention expressed twice.
 */
class EndAdaptiveSession extends Action
{
    public function __construct(private readonly AdaptiveSeal $seal) {}

    public function handle(User $student, string $sessionUuid): AdaptiveSession
    {
        /** @var AdaptiveSession $session */
        $session = AdaptiveSession::query()
            ->withoutWorkspaceScope()
            // The ownership condition is the whole guard: `WorkspaceScope` adds
            // nothing for a student, so without it any uuid resolves.
            ->where('student_user_id', $student->getKey())
            ->where('uuid', $sessionUuid)
            ->firstOrFail();

        if (! $session->status->isOpen()) {
            return $session;
        }

        /*
        | ⚠️ THE CLAIM DECIDES WHO SEALS THE ATTEMPT. Two tabs pressing «إنهاء»
        | both read `running`; the conditional UPDATE gives exactly one of them a
        | true, and only that one writes the score. Without it the second write
        | would overwrite a `mastered` session as merely `ended`.
        */
        if ($session->claimClosure(AdaptiveStatus::Ended)) {
            $this->seal->seal($session);
        }

        return $session->refresh();
    }
}
