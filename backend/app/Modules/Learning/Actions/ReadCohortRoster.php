<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Gamification\Actions\ReadBadgesFor;
use App\Modules\Gamification\Actions\ReadRanksFor;
use App\Modules\Gamification\Support\LeaderboardScope;
use App\Modules\Learning\Models\Cohort;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;

/**
 * Who is in the group — a face, a name, a level, a rank and the newest badges
 * (FR-050 · FR-051).
 *
 * ⚠️ `ReadSessionRoster` IN A SECOND SHAPE, DELIBERATELY WITHOUT ITS EXTRAS. That
 * class unions bookings, attendance and the host because a room can be entered
 * three ways; a group has exactly one door, so the list IS
 * `activeMemberIdsFor()` — which is also how FR-053 holds by construction rather
 * than by a filter somebody could drop. No host row, and therefore no `role`
 * key: nobody in this list is anything but a student.
 *
 * ⚠️ NOT ONE FIELD ABOUT ATTENDANCE, A STAY, A MARK OR A TEACHER'S NOTE (FR-052).
 * Those are `ATTENDANCE_VIEW`'s questions and this route opens to every member,
 * so carrying one here would hand each student their classmates' record.
 * `CohortRosterExposureTest` walks every key in the payload against the allowlist.
 *
 * ⚠️ AND IT RETURNS AN ARRAY RATHER THAN A RESOURCE, exactly as its sibling does.
 * The tasks name a `CohortRosterResource`; a Resource whose only job is to copy a
 * typed array through would be a second place for a field to be added, and the
 * allowlist test is the guard either way.
 */
class ReadCohortRoster extends Action
{
    public function __construct(
        private readonly CohortDirectory $cohorts,
        private readonly ReadBadgesFor $badges,
        private readonly ReadRanksFor $ranks,
    ) {}

    /**
     * ⚠️ THE RANK SCOPE IS THE TEACHER'S, KEYED BY THE WORKSPACE — the same key
     * `ChatRankStamper` uses, and that is the whole reason. The group's thread is
     * ONE TAB AWAY from this list on the same page: a course-scoped key here
     * would show the same student «المركز ٧» beside their message and
     * «المركز ٣» beside their name, which is the two-spellings defect wearing a
     * number.
     *
     * ⚠️ AND `rank` AND `level` ARE ABSENT KEYS, NEVER ZEROS (FR-051). The boards
     * roll up nightly, so a member who joined this morning is on none of them,
     * and a member who has earned nothing ever has no `student_progress` row
     * either. Absence is a state; «المركز ٠» is a number printed beside a
     * student's name in front of their class. `points` is not copied out of
     * `ReadRanksFor` at all — it is not on the allowlist and nothing on this
     * screen asks for it.
     *
     * @return list<array{
     *     uuid: string,
     *     name: string,
     *     avatar_url: string|null,
     *     badges: list<array{key: string, name_ar: string, icon: string|null}>,
     *     level?: int,
     *     rank?: int
     * }>
     */
    public function handle(Cohort $cohort): array
    {
        $memberIds = $this->cohorts->activeMemberIdsFor((int) $cohort->getKey());

        if ($memberIds === []) {
            return [];
        }

        /*
         * ⚠️ `first_name` AND `last_name`, NEVER `name`. `users` has no such
         * column — it is an accessor over the two — so a constrained eager load
         * naming the attribute the screen prints returns an empty string for
         * every row, with no error and a 200. Six call sites across four modules
         * shipped that way once (spec 010 · T186).
         */
        $users = User::query()
            ->whereIn('id', $memberIds)
            ->with('studentProfile:id,user_id,avatar_path')
            ->orderBy('first_name')
            ->get(['id', 'uuid', 'first_name', 'last_name']);

        $badges = $this->badges->handle($memberIds);
        $ranks = $this->ranks->handle(
            $memberIds,
            LeaderboardScope::Teacher->keyFor((string) $cohort->workspace_id),
        );

        return array_values($users
            ->map(function (User $user) use ($badges, $ranks): array {
                $id = (int) $user->getKey();
                $standing = $ranks[$id] ?? [];

                $row = [
                    'uuid' => (string) $user->uuid,
                    'name' => $user->name,
                    // The photo lives on the PUBLIC disk and is served by path —
                    // no signature, unlike a chat attachment: a profile photo is
                    // not a private object.
                    'avatar_url' => $user->studentProfile?->avatar_path === null
                        ? null
                        : asset('storage/'.$user->studentProfile->avatar_path),
                    'badges' => $badges[$id] ?? [],
                ];

                if (($standing['level'] ?? null) !== null) {
                    $row['level'] = (int) $standing['level'];
                }

                if (($standing['rank'] ?? null) !== null) {
                    $row['rank'] = (int) $standing['rank'];
                }

                return $row;
            })
            ->all());
    }
}
