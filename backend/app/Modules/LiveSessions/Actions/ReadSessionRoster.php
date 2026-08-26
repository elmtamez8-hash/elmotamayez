<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\Gamification\Actions\ReadBadgesFor;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;
use Illuminate\Support\Collection;

/**
 * Who the people in the room ARE — the other half of `FR-006`.
 *
 * ⚠️ THE TICKET CARRIES A UUID AND NOTHING ELSE, ON PURPOSE. `IssueJoinTicket`
 * sets the participant identity to `$user->uuid` and never the name, because the
 * identity is echoed by the provider to everyone in the room and a name inside a
 * provider's payload is a name we no longer control. The consequence is that the
 * participant list drew raw uuids — so this is the join that turns them back into
 * people, over OUR authenticated route, for a reader we have already checked.
 *
 * ⚠️ IT IS NOT THE REGISTER, AND THE DIFFERENCE IS A PERMISSION. `ATTENDANCE_VIEW`
 * says «you may read who attended, for how long, and why a mark was changed»;
 * every seat holder holds THIS, so nothing here may carry a status, a stay, a
 * mark or a note. What it carries is what the room's own chat already shows every
 * participant — a name, a face and a badge.
 *
 * ⚠️ AND THE FRONTEND RENDERS ONLY WHO IS ACTUALLY CONNECTED. This answers for
 * everyone who could be in the room, which is deliberately wider: a list built
 * from bookings alone loses the assistant who joined, and a list drawn straight
 * from this one would publish the roll of everybody who did NOT show up.
 */
class ReadSessionRoster extends Action
{
    public function __construct(private readonly ReadBadgesFor $badges) {}

    /**
     * @return list<array{
     *     uuid: string,
     *     name: string,
     *     role: string,
     *     avatar_url: string|null,
     *     badges: list<array{key: string, name_ar: string, icon: string|null}>
     * }>
     */
    public function handle(ClassSession $session): array
    {
        // A null relation casts to 0, which the filter below drops — the session
        // whose teacher profile was removed has no host to name, not a host with
        // id zero.
        $hostUserId = (int) $session->teacherProfile?->user_id;

        $bookedIds = $session->bookings()
            ->withoutWorkspaceScope()
            ->pluck('student_user_id')
            ->map(fn ($id): int => (int) $id);

        /*
         * Attendance is in the union because a booking is not the only way into
         * a room: an assistant with `sessions.host` has no seat and no booking,
         * and a row is written for them the first time they ping. Without it
         * their tile keeps the uuid it started with.
         */
        $attendedIds = $session->attendances()
            ->withoutWorkspaceScope()
            ->pluck('student_user_id')
            ->map(fn ($id): int => (int) $id);

        /** @var list<int> $userIds */
        $userIds = $bookedIds
            ->merge($attendedIds)
            ->push($hostUserId)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return [];
        }

        /*
         * ⚠️ `first_name` AND `last_name`, NEVER `name`. `users` has no such
         * column — it is an accessor over the two — so a constrained eager load
         * that names the attribute the screen prints returns an empty string for
         * every row, with no error and a 200. Six call sites shipped that way
         * once (spec 010 · T186).
         */
        $users = User::query()
            ->whereIn('id', $userIds)
            ->with([
                'studentProfile:id,user_id,avatar_path',
                'teacherProfile:id,user_id,photo_path',
            ])
            ->get(['id', 'uuid', 'first_name', 'last_name']);

        $badges = $this->badges->handle($userIds);
        $booked = $bookedIds->flip();

        return array_values($users
            ->map(fn (User $user): array => [
                'uuid' => (string) $user->uuid,
                'name' => $user->name,
                'role' => $this->roleFor($user, $hostUserId, $booked),
                'avatar_url' => $this->avatarUrl($user),
                'badges' => $badges[(int) $user->getKey()] ?? [],
            ])
            ->all());
    }

    /**
     * @param  Collection<int, int>  $booked  seat holders, flipped to a lookup
     */
    private function roleFor(User $user, int $hostUserId, Collection $booked): string
    {
        if ((int) $user->getKey() === $hostUserId) {
            return 'host';
        }

        // Not «student» by default: an assistant holds no seat, and calling them
        // one in front of the class is a lie the screen would repeat every week.
        return $booked->has((int) $user->getKey()) ? 'student' : 'staff';
    }

    /**
     * The student's photo or the teacher's, whichever this person has.
     *
     * Both live on the PUBLIC disk and are served by path — no signature, unlike
     * a chat attachment, because a profile photo is not a private object and a
     * short-lived URL for it would expire inside a two-hour lesson.
     */
    private function avatarUrl(User $user): ?string
    {
        $path = $user->studentProfile?->avatar_path;

        if ($path === null) {
            $path = $user->teacherProfile?->photo_path;
        }

        return $path === null ? null : asset('storage/'.$path);
    }
}
