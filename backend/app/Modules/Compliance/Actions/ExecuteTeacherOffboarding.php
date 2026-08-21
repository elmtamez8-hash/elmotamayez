<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Enums\OffboardingStatus;
use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Compliance\Exceptions\OffboardingNotSettled;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SettlementClearance;

/**
 * The officer finalises an exit (spec 013 · FR-032 · FR-035 … FR-037 · SC-013).
 *
 * ⚠️ THE SETTLEMENT IS RE-READ HERE AND NOT TRUSTED FROM THE REQUEST. Units keep
 * accruing all through the notice period — the teacher is still delivering the
 * lessons the notice exists to let them finish — so `settlement_cleared_at`
 * stamped thirty days ago describes a balance that has moved since. Reading it
 * once at request and believing it at completion is FR-032 satisfied on paper and
 * broken in fact.
 *
 * ⚠️ AND THE COMPLETION IS A CONDITIONAL UPDATE, both the check and the claim.
 * Two officers pressing complete together would otherwise both pass the read,
 * both write, and both fan out `TeacherOffboardingCompleted` — memberships ended
 * twice, tokens revoked twice, recordings re-dated twice. None of it reverses.
 * Never `lockForUpdate()`, a no-op on SQLite.
 */
class ExecuteTeacherOffboarding extends Action
{
    public function __construct(private readonly SettlementClearance $clearance) {}

    public function handle(TeacherOffboarding $offboarding, User $completedBy): TeacherOffboarding
    {
        if ($offboarding->status === OffboardingStatus::Completed) {
            return $offboarding;
        }

        $teacher = $offboarding->teacher;
        $workspaceId = (int) $offboarding->workspace_id;

        if ($teacher === null || ! $this->clearance->isCleared($teacher, $workspaceId)) {
            /*
            | ⚠️ REFUSAL WRITES `settlement_pending` AND THAT IS RETRYABLE ON
            | PURPOSE. The claim below matches `notice_period`, so parking a
            | refused row in a state the claim cannot see would strand it for ever
            | — the dead end US4's `executed_by_user_id` produced, reached through
            | a status instead of a column. The clearing path below puts it back.
            */
            TeacherOffboarding::query()
                ->whereKey($offboarding->getKey())
                ->where('status', '!=', OffboardingStatus::Completed->value)
                ->update([
                    'status' => OffboardingStatus::SettlementPending->value,
                    'settlement_cleared_at' => null,
                    'updated_at' => now(),
                ]);

            throw new OffboardingNotSettled;
        }

        /*
        | Cleared: stamp it and put the row back in `notice_period`, which is the
        | only state the claim below accepts. Both writes are conditional on the
        | row not already being completed, so a second operator arriving here finds
        | nothing left to claim.
        */
        TeacherOffboarding::query()
            ->whereKey($offboarding->getKey())
            ->where('status', '!=', OffboardingStatus::Completed->value)
            ->update([
                'status' => OffboardingStatus::NoticePeriod->value,
                'settlement_cleared_at' => now(),
                'updated_at' => now(),
            ]);

        /*
        | ⚠️ THE NOTICE PERIOD IS PART OF THE GUARD, AND THE TASK'S OWN WHERE OMITS
        | IT. FR-033 promises students an ANNOUNCED period before service stops;
        | completing inside it would end their access on a date nobody gave them,
        | which is the requirement broken by the endpoint that enforces the rest of
        | it. `<=` on a timestamp bound, so the deadline itself passes.
        */
        $claimed = TeacherOffboarding::query()
            ->whereKey($offboarding->getKey())
            ->where('status', OffboardingStatus::NoticePeriod->value)
            ->whereNotNull('settlement_cleared_at')
            ->where('notice_ends_at', '<=', now())
            ->update([
                'status' => OffboardingStatus::Completed->value,
                'completed_by_user_id' => $completedBy->getKey(),
                'completed_at' => now(),
                'content_export_path' => $this->contentArchiveFor($teacher),
                'updated_at' => now(),
            ]);

        $offboarding->refresh();

        if ($claimed === 0) {
            /*
            | Either another officer got there first — in which case the row is
            | already completed and the caller has nothing to do — or the notice
            | has not run out, which is a refusal the controller turns into a
            | sentence naming the date.
            */
            if ($offboarding->status !== OffboardingStatus::Completed) {
                throw new OffboardingNotSettled('لم تنتهِ مهلةُ الإخطار بعد.');
            }

            return $offboarding;
        }

        TeacherOffboardingCompleted::dispatch($offboarding);

        return $offboarding;
    }

    /**
     * Where the teacher's own content archive ended up (FR-034).
     *
     * A pointer, not a second archive: `RequestTeacherOffboarding` opened an
     * ordinary export request, which walks `CoursesPersonalData` and every other
     * owner through the streaming writer US3 built. Null while that export is
     * still running or its link has already expired — the column records what was
     * handed over, and "nothing yet" is an honest answer to that.
     */
    private function contentArchiveFor(User $teacher): ?string
    {
        $path = DataRequest::query()
            ->where('subject_user_id', $teacher->getKey())
            ->where('type', DataRequestType::Export->value)
            ->where('status', DataRequestStatus::Completed->value)
            ->latest('id')
            ->value('export_path');

        return $path === null ? null : (string) $path;
    }
}
