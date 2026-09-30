<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Actions\IssueJoinTicket;
use App\Modules\LiveSessions\Actions\PerformHostAction;
use App\Modules\LiveSessions\Data\PublishRights;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Support\Collection;

/**
 * Who may speak, and who may share a screen — the host's decisions, read back.
 *
 * ⛔ ONE CLASS OWNS THE RULE AND BOTH DOORS ASK IT: {@see IssueJoinTicket} when a
 * student walks in (so a muted student who reloads comes back muted) and
 * {@see PerformHostAction} when the host presses a button (so the people already
 * inside change at once). Two spellings of «may she speak» would be the
 * «two doors disagreeing» defect this module has paid for three times.
 *
 * The rule (owner decisions 2026-09-30):
 *
 *  · MICROPHONE — open unless the host muted THIS student (`mic_locked_at`), or
 *    the whole room is locked («اكتم الجميع», `class_sessions.mics_locked_at`)
 *    and the host has not let THIS student speak (`mic_allowed_at`). A mute and
 *    an allow on one seat are mutually exclusive: each press clears the other.
 *  · SCREEN — closed unless the host allowed THIS student
 *    (`screen_share_allowed_at`). A class of thirty that can each put a screen
 *    over the lesson is a lesson nobody is teaching.
 *  · CAMERA — always the student's own choice, never here.
 *
 * ⚠️ «اسمح للجميع بالكلام» LIFTS THE ROOM LOCK AND LEAVES EVERY PER-SEAT MUTE
 * STANDING. The host who muted one disruptive student and then opened the room
 * for questions did not mean to un-mute that student; lifting a named mute is
 * «اسمح بالكلام» on that row. The allow-overrides are cleared with the lock
 * (they only mean something while it is on), and a re-lock clears them too —
 * the student who was let speak for one question is not still speaking an hour
 * later.
 *
 * ⚠️ STUDENTS ONLY. Every rule here is about a seat holder; a host of the session
 * (the teacher, a co-teacher, an assistant passing the host gate) is never in the
 * map a bulk action walks, so no room control can mute them.
 */
final class RoomMediaRights
{
    public function __construct(private readonly RoomRevocation $revocation) {}

    /** The rights a student's ticket carries — one read of her own row. */
    public function forStudent(ClassSession $session, User $user): PublishRights
    {
        $row = Attendance::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $user->getKey())
            ->first(['id', 'mic_locked_at', 'mic_allowed_at', 'screen_share_allowed_at']);

        return self::resolve($session->mics_locked_at !== null, $row);
    }

    public static function resolve(bool $roomLocked, ?Attendance $row): PublishRights
    {
        $micLocked = $row?->mic_locked_at !== null;
        $micAllowed = $row?->mic_allowed_at !== null;

        return new PublishRights(
            microphone: ! $micLocked && (! $roomLocked || $micAllowed),
            screenShare: $row?->screen_share_allowed_at !== null,
        );
    }

    /**
     * Every student of this session with the rights they hold now — what a room
     * action pushes to whoever of them is inside.
     *
     * @return array<string, PublishRights> user uuid ⇒ rights
     */
    public function forStudentsOf(ClassSession $session): array
    {
        $students = $this->studentsOf($session);

        if ($students->isEmpty()) {
            return [];
        }

        $rows = Attendance::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->whereIn('student_user_id', $students->keys())
            ->get(['id', 'student_user_id', 'mic_locked_at', 'mic_allowed_at', 'screen_share_allowed_at'])
            ->keyBy(fn (Attendance $row): int => (int) $row->student_user_id);

        $roomLocked = $session->mics_locked_at !== null;
        $rights = [];

        foreach ($students as $id => $student) {
            $rights[(string) $student->uuid] = self::resolve($roomLocked, $rows->get($id));
        }

        return $rights;
    }

    /**
     * The people being TAUGHT here: a booked seat, and not a host.
     *
     * ⚠️ THE HOST GATE IS ASKED OF EVERY SEAT HOLDER, not «the teacher's user
     * id»: an assistant who passes `ClassSessionPolicy::host` is a host of this
     * lesson whatever else they hold, and «اكتم الجميع» must never reach them.
     * The same gate `RoomRevocation::isHost()` asks at the door.
     *
     * @return Collection<int, User> keyed by user id
     */
    public function studentsOf(ClassSession $session): Collection
    {
        $ids = $session->bookings()
            ->withoutWorkspaceScope()
            ->where('status', BookingStatus::Booked)
            ->pluck('student_user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get()
            ->reject(fn (User $user): bool => $this->revocation->isHost($session, $user))
            ->keyBy(fn (User $user): int => (int) $user->getKey());
    }

    /** @return list<string> the student identities a bulk action may touch */
    public function studentIdentitiesOf(ClassSession $session): array
    {
        return array_values($this->studentsOf($session)
            ->map(fn (User $user): string => (string) $user->uuid)
            ->all());
    }
}
