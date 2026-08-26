<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BroadcastProviderResolver;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Mute, remove, end — one person or the whole room — and let somebody back in.
 *
 * Who may do it is the policy's call; this is about whether the provider can —
 * for the ones that are actually the provider's to do.
 *
 * **Mute and remove** are claims about media the provider holds, so a capability
 * it never claimed throws rather than no-ops: a teacher pressing "mute" on a
 * microphone that stays open, with nothing saying so, is worse than a provider
 * that has no mute at all.
 *
 * **Ending is ours.** `room_closed_at` is what refuses re-entry (FR-015), and
 * the provider's `closeRoom()` is a teardown hook the only implemented provider
 * fulfils as a documented no-op. Sending "end" through `hostAction()` made the
 * teacher's one host button dead against every provider that does not claim
 * hostControls — which today is all of them: 501, the door never shut, and the
 * register left waiting for the scheduled sweep. `CloseBroadcastRoom` still
 * calls the provider, so a provider with a real teardown is not skipped.
 *
 * **And readmission is ours for the same reason.** It clears a stamp of ours;
 * there is nothing for a provider to do, so routing it through `hostAction()`
 * would answer 501 on a button that needs no provider at all — the End defect
 * reached from a second direction.
 */
class PerformHostAction extends Action
{
    public function __construct(
        private readonly BroadcastProviderResolver $providers,
        private readonly CloseBroadcastRoom $closeRoom,
    ) {}

    /**
     * `$actor` is the host who pressed the button, and it is not authorisation:
     * `BroadcastController` asks the policy, exactly as it did before the bulk
     * actions existed. It is here so «الجميع» can exclude the one person it must
     * never include — a teacher who removes themselves leaves the room open, the
     * recording running, and nobody inside who can close it.
     */
    public function handle(ClassSession $session, HostAction $action, ?User $target = null, ?User $actor = null): void
    {
        if ($action->requiresTarget() && $target === null) {
            throw new DomainException('حدّد المشارك المقصود.');
        }

        if ($action === HostAction::End) {
            $this->closeRoom->handle($session);

            return;
        }

        if ($action === HostAction::Readmit) {
            $this->readmit($session, $target);

            return;
        }

        $identities = $this->providers->for($session)->hostAction($session, $action, $target, $actor);

        if ($action === HostAction::Remove || $action === HostAction::RemoveAll) {
            $this->recordRemoval($session, $identities);
        }
    }

    /**
     * ⚠️ WITHOUT THIS, «إخراج» LASTED ONE REFRESH.
     *
     * Removing somebody was a provider call and nothing else: the participant was
     * disconnected, the room stayed open, the seat stayed booked, and
     * `IssueJoinTicket` minted a fresh ticket to the same person a second later.
     * The teacher's one control over a disruptive student cost them an F5.
     * Reported from a real lesson on 2026-08-26.
     *
     * ⚠️ AND IT IS STAMPED FROM WHAT THE PROVIDER CONFIRMS, never from a list
     * rebuilt here. Only the provider sees who was in the room; deriving "who is
     * present" from `last_ping_at` beside `RecordPresencePing`'s own arithmetic
     * would be a second spelling of one question — and its failure direction is
     * this very bug surviving, for whoever the heuristic missed.
     *
     * A row is created when there is none, which is only reachable from the
     * single-target form: the person was confirmed in the room, so a row with no
     * stay is the truth about them rather than an invention.
     *
     * @param  list<string>  $identities  participant identities — our user uuids
     */
    private function recordRemoval(ClassSession $session, array $identities): void
    {
        if ($identities === []) {
            return;
        }

        $userIds = User::query()->whereIn('uuid', $identities)->pluck('id', 'uuid');

        foreach ($identities as $identity) {
            $userId = $userIds[$identity] ?? null;

            if ($userId === null) {
                continue;
            }

            $attendance = Attendance::query()->withoutWorkspaceScope()->firstOrNew([
                'class_session_id' => $session->getKey(),
                'student_user_id' => (int) $userId,
            ]);

            /*
             * ⚠️ NOT `updateOrCreate`: its second array is applied on UPDATE as
             * well as on create, so a status supplied for the new-row case would
             * overwrite the register's verdict for everybody who already has one.
             * `attendances.status` is NOT NULL with no default, and `absent` is
             * the honest starting point for a row the sweep will correct.
             */
            if (! $attendance->exists) {
                $attendance->fill([
                    'workspace_id' => $session->workspace_id,
                    'status' => AttendanceStatus::Absent->value,
                ]);
            }

            $attendance->fill(['removed_at' => now()])->save();
        }
    }

    /**
     * Let somebody back in.
     *
     * A removal pressed in error must be undoable — the alternative is a teacher
     * who cannot return a student to the lesson they are paying for. Clearing the
     * stamp is the whole of it: the door reads `removed_at`, and the student's
     * own page asks for a ticket again on its next heartbeat.
     */
    private function readmit(ClassSession $session, ?User $target): void
    {
        Attendance::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $target?->getKey())
            ->update(['removed_at' => null]);
    }
}
