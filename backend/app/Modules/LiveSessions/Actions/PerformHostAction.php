<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Exceptions\BroadcastProviderUnavailable;
use App\Modules\LiveSessions\Exceptions\UnsupportedCapability;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BroadcastProviderResolver;
use App\Modules\LiveSessions\Support\RoomMediaRights;
use App\Modules\LiveSessions\Support\RoomRevocation;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

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
 *
 * **The microphone and the screen are PERMISSIONS (2026-09-30).** «كتم» used to
 * mute a published track and nothing else, which the student undid with one
 * tap of her own button — and a reload handed her a fresh ticket that could
 * speak. The decision is now STORED first (on the seat, or on the session for
 * «اكتم الجميع») and then pushed to whoever is inside; the next ticket reads the
 * same columns ({@see RoomMediaRights}). None of these actions can end a lesson
 * or disconnect anyone: they never reach `End`, `closeRoom()` or a removal, and
 * the provider's permission update restates everything a student keeps.
 *
 * **And a host is never the target of a student control.** Mute, remove, allow
 * and the screen toggles refuse a target who passes the host gate or holds no
 * seat here — a co-teacher cannot be muted or put out by the button meant for a
 * student, and every bulk form walks the seat holders alone.
 */
class PerformHostAction extends Action
{
    public function __construct(
        private readonly BroadcastProviderResolver $providers,
        private readonly CloseBroadcastRoom $closeRoom,
        private readonly RoomMediaRights $mediaRights,
        private readonly RoomRevocation $revocation,
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

        if ($target !== null && $action->requiresTarget()) {
            $this->assertStudentTarget($session, $target);
        }

        $provider = $this->providers->for($session);

        if ($action->changesPublishRights()) {
            $this->changePublishRights($provider, $session, $action, $target);

            return;
        }

        $identities = $provider->hostAction(
            $session,
            $action,
            $target,
            $actor,
            $action->isBulk() ? $this->mediaRights->studentIdentitiesOf($session) : [],
        );

        if ($action === HostAction::Remove || $action === HostAction::RemoveAll) {
            $this->recordRemoval($session, $identities);
        }
    }

    /**
     * ⚠️ THE STUDENT CONTROLS ARE FOR STUDENTS. A host of this session — the
     * teacher, a co-teacher, an assistant passing the host gate — is refused
     * here as a target, and so is anyone with no seat (staff in the room). The
     * buttons are hidden on those rows; this is the door, because a hidden
     * button is not a guard.
     */
    private function assertStudentTarget(ClassSession $session, User $target): void
    {
        if ($this->revocation->isHost($session, $target)) {
            throw new DomainException('هذا من مُضيفي الحصّة، وأدواتُ الطلابِ لا تُطبَّقُ عليه.');
        }

        if (! $session->holdsSeat($target)) {
            throw new DomainException('هذا المشاركُ ليس طالباً في هذه الحصّة.');
        }
    }

    /**
     * Store the decision, then apply it to whoever is inside.
     *
     * ⚠️ IN THAT ORDER, AND THE STORE IS NOT UNDONE WHEN THE PROVIDER FAILS. The
     * decision is the teacher's and it is idempotent: a second press re-applies
     * it. Stored first, a student who joins a second later is already muted by
     * her ticket; applied first and stored after, a crash between the two leaves
     * a muted student whose next reload un-mutes her.
     *
     * The capability is asked BEFORE anything is written: a provider that cannot
     * change a participant's permissions answers 501 with nothing recorded,
     * rather than a stored mute the room never sees.
     */
    private function changePublishRights(BroadcastProviderInterface $provider, ClassSession $session, HostAction $action, ?User $target): void
    {
        if (! $provider->capabilities()->hostControls) {
            throw UnsupportedCapability::for($provider->identifier(), 'hostControls');
        }

        $this->recordPublishRights($session, $action, $target);

        $rights = $target === null
            ? $this->mediaRights->forStudentsOf($session)
            : [(string) $target->uuid => $this->mediaRights->forStudent($session, $target)];

        try {
            $provider->applyPublishRights($session, $rights);
        } catch (BroadcastProviderUnavailable $e) {
            // The adapter's own sentence says «nothing changed», which is no
            // longer true: the decision is stored and every ticket carries it.
            throw new BroadcastProviderUnavailable(
                'سُجِّل قرارُك ويسري على كلِّ من يدخلُ الغرفةَ بعدَ الآن، لكنّ خدمةَ البثِّ لم تستجبْ لتطبيقِه على الحاضرين — اضغطْ مرّةً أخرى بعدَ قليل.',
                previous: $e,
            );
        }
    }

    /**
     * The columns {@see RoomMediaRights} reads. See its docblock for the rule;
     * this is only the writing of it.
     */
    private function recordPublishRights(ClassSession $session, HostAction $action, ?User $target): void
    {
        DB::transaction(function () use ($session, $action, $target): void {
            if ($action === HostAction::MuteAll || $action === HostAction::AllowAllMics) {
                $this->lockRoomMics($session, $action === HostAction::MuteAll);

                return;
            }

            $columns = match ($action) {
                HostAction::Mute => ['mic_locked_at' => now(), 'mic_allowed_at' => null],
                HostAction::AllowMic => ['mic_locked_at' => null, 'mic_allowed_at' => now()],
                HostAction::AllowScreenShare => ['screen_share_allowed_at' => now()],
                HostAction::RevokeScreenShare => ['screen_share_allowed_at' => null],
                // `changesPublishRights()` is the guard above; nothing else
                // arrives here, and nothing here can end or empty a room.
                default => [],
            };

            if ($columns !== []) {
                $this->seatRow($session, $target)->fill($columns)->save();
            }
        });
    }

    /**
     * «اكتم الجميع» / «اسمح للجميع بالكلام».
     *
     * ⚠️ THE LOCK IS STAMPED ONCE (`whereNull`) — a second press is not a second
     * decision — and every «may speak» override is cleared with it, whichever
     * way it goes: re-locking means the one student let through for a question
     * is silenced again, and unlocking makes the override meaningless. Per-seat
     * MUTES are left standing on unlock; see {@see RoomMediaRights}.
     */
    private function lockRoomMics(ClassSession $session, bool $locked): void
    {
        $query = ClassSession::query()->withoutWorkspaceScope()->whereKey($session->getKey());

        if ($locked) {
            $query->whereNull('mics_locked_at')->update(['mics_locked_at' => now()]);
        } else {
            $query->update(['mics_locked_at' => null]);
        }

        Attendance::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->whereNotNull('mic_allowed_at')
            ->update(['mic_allowed_at' => null]);

        // The row the caller holds is read by the rights resolver next.
        $session->setAttribute('mics_locked_at', ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereKey($session->getKey())
            ->value('mics_locked_at'));
        $session->syncOriginalAttribute('mics_locked_at');
    }

    /**
     * This student's register row, made if they have none yet — the host may
     * decide before the student's first heartbeat wrote one.
     *
     * ⚠️ NOT `updateOrCreate`: its second array is applied on UPDATE as well as
     * on create, so a status supplied for the new-row case would overwrite the
     * register's verdict for everybody who already has one. `absent` is the
     * honest starting point for a row the sweep will correct.
     */
    private function seatRow(ClassSession $session, ?User $target): Attendance
    {
        $attendance = Attendance::query()->withoutWorkspaceScope()->firstOrNew([
            'class_session_id' => $session->getKey(),
            'student_user_id' => (int) $target?->getKey(),
        ]);

        if (! $attendance->exists) {
            $attendance->fill([
                'workspace_id' => $session->workspace_id,
                'status' => AttendanceStatus::Absent->value,
            ]);
        }

        return $attendance;
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
