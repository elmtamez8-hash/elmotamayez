<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Enums\OffboardingStatus;
use App\Modules\Compliance\Events\TeacherOffboardingRequested;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Compliance\Support\ComplianceSettings;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SettlementClearance;

/**
 * A teacher asks to leave (spec 013 · US6 · FR-032 … FR-034).
 *
 * ⚠️ THE TEACHER REQUESTS AND NEVER COMPLETES. Completion revokes access,
 * ends memberships and fixes the recordings' retention — none of it reversible —
 * and FR-032 makes it conditional on money being settled in both directions. A
 * self-service complete button would make the officer's endpoints decorations,
 * exactly as a self-service erasure would.
 */
class RequestTeacherOffboarding extends Action
{
    public function __construct(
        private readonly SettlementClearance $clearance,
        private readonly CreateDataRequest $requests,
    ) {}

    public function handle(Workspace $workspace, User $teacher): TeacherOffboarding
    {
        $existing = TeacherOffboarding::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', '!=', OffboardingStatus::Completed->value)
            ->first();

        if ($existing !== null) {
            /*
            | ⚠️ THE OPEN ONE IS RETURNED, NOT A SECOND ROW. A teacher pressing the
            | button twice — or a double-submitted form — would otherwise open two
            | notice periods for one workspace, and the officer's queue would show
            | one exit as two with different deadlines. The same reason
            | `data_requests` carries `open_key`; here a partial index is
            | unnecessary because the read is cheap and the row is not a race
            | anybody wins money on.
            */
            return $existing;
        }

        $offboarding = new TeacherOffboarding([
            'workspace_id' => $workspace->getKey(),
            'teacher_user_id' => $teacher->getKey(),
            /*
            | FR-033 — the notice is ANNOUNCED, so it is computed once, at request,
            | and every student is told the same date. Recomputing it at completion
            | would move a deadline people had already been given.
            */
            'notice_ends_at' => now()->addDays(ComplianceSettings::offboardingNoticeDays()),
        ]);

        /*
        | The settlement is read HERE only to decide which state the officer's queue
        | shows — "waiting on money" is a different row from "serving notice", and
        | the enum's own docblock says the wait has to be visible. It is re-read at
        | completion because units keep accruing through the notice period: a
        | teacher still delivering lessons is still earning, so a clearance stamped
        | now is stale by the time anybody presses complete.
        */
        $cleared = $this->clearance->isCleared($teacher, (int) $workspace->getKey());

        $offboarding->forceFill([
            'status' => $cleared
                ? OffboardingStatus::NoticePeriod->value
                : OffboardingStatus::SettlementPending->value,
            'settlement_cleared_at' => $cleared ? now() : null,
        ])->save();

        /*
        | ⚠️ FR-034 REUSES THE EXPORT MACHINERY RATHER THAN GROWING A SECOND ONE.
        | `CoursesPersonalData::export()` already walks a subject's WORKSPACES —
        | written that way in US3 precisely because a course built by an assistant
        | inside the teacher's workspace is the teacher's course — so a plain export
        | request for this teacher produces the content archive, with the streaming,
        | the field allowlist, the signed download and the expiry that took US3 a
        | phase to get right.
        |
        | `open_key` means a teacher who already has an export open gets THAT one
        | rather than a second archive, which is correct: it is the same set of
        | files either way.
        */
        $this->requests->handle($teacher, (string) $teacher->uuid, DataRequestType::Export);

        TeacherOffboardingRequested::dispatch($offboarding);

        return $offboarding;
    }
}
