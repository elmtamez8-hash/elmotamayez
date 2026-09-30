<?php

declare(strict_types=1);

namespace App\Modules\Community\Listeners;

use App\Modules\Community\Support\AssistantAppointments;
use App\Modules\Tenancy\Actions\AcceptInvitation;
use App\Modules\Tenancy\Events\WorkspaceMemberAdded;

/**
 * Somebody joined a teacher's workspace, so they are on the teacher's team.
 *
 * ⚠️ NO `POST /manage/assistants`, AND THAT IS NOT A SHORTCUT. `workspace_members`
 * is written by `AcceptInvitation` and `CreateWorkspace` and by nothing else —
 * the invitation, the acceptance and the member list are all shipped, with their
 * own screens. A second way in would be a second membership story, and the two
 * disagree the first time somebody uses the older one.
 *
 * ⚠️ THE EXCLUSION LIST IS THE WHOLE CLASSIFICATION, AND IT FAILS SAFE. An owner
 * never arrives by invitation, a co-teacher reads their own settlement, and a
 * student is somebody's customer rather than somebody's staff — walling a student
 * would refuse them `orders.create`, which is buying the course. EVERYBODY ELSE IS
 * AN ASSISTANT, including the custom role an owner invents, which is exactly the
 * person FR-003 is about. Written the other way round («create one for
 * `assistant-teacher`») a role named «مصحّح» would carry no assignment, no wall
 * and no confinement, and nothing anywhere would say so.
 *
 * ⚠️ THE WORKSPACE COMES FROM THE EVENT, NEVER FROM THE CONTEXT. `BelongsToWorkspace`
 * would auto-fill `workspace_id` from whatever the accepter's session resolves to,
 * and an invitation is accepted from wherever that person happens to be signed in
 * — which is the workspace they were in a moment ago, not the one they just
 * joined.
 *
 * ⚠️ AND RE-ACCEPTANCE IS A RE-APPOINTMENT. `WorkspaceMemberAdded` fires outside
 * the "already a member" guard in {@see AcceptInvitation},
 * and a teacher who withdrew somebody and invited them back must not be answered
 * with a unique-key violation on a row that says «revoked». `revoked_at` is not
 * fillable — it is claimed by a conditional UPDATE — so it is cleared with
 * `forceFill`, and clearing it is the whole of the re-appointment: the wall, the
 * confinement and the attribution all read this one row.
 *
 * ⚠️ SINCE 2026-09-30 A RE-APPOINTMENT STARTS UNCONFINED (the old scope rows are
 * dropped), and the rule lives in {@see AssistantAppointments} with the role
 * change and the removal that also open and close this row.
 */
class CreateAssistantAssignment
{
    public function __construct(private readonly AssistantAppointments $appointments) {}

    public function handle(WorkspaceMemberAdded $event): void
    {
        if (! AssistantAppointments::isAssistantRole($event->role)) {
            return;
        }

        $this->appointments->appoint(
            (int) $event->workspace->getKey(),
            (int) $event->user->getKey(),
            $event->workspace->owner_user_id === null ? null : (int) $event->workspace->owner_user_id,
        );
    }
}
