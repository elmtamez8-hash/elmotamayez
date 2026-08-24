<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Models\ReportCard;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;

/**
 * Who may read one student's cumulative card (FR-040).
 *
 * ⚠️ THE TEACHER IS NOT HERE, AND THAT IS THE POINT. A card spans every teacher
 * the student studies with, so a teacher who could `view` one would be reading a
 * colleague's grades — NFR-001أ. The teacher's route returns their own SEGMENT,
 * which is a different object with a different query.
 *
 * ⚠️ AND THE UUID IS RESOLVED INSIDE THE ACTION, NEVER BY IMPLICIT BINDING.
 * `report_cards` carries no `workspace_id` by design and a student is a member of
 * no workspace at all, so no scope adds any condition on the route a student
 * reaches — an implicit `{card}` would resolve any child's card on the platform
 * and hand it to whoever typed the uuid.
 */
class ReportCardPolicy
{
    public function __construct(private readonly GuardianDirectory $guardians) {}

    public function view(User $user, ReportCard $card): bool
    {
        if ((int) $card->student_user_id === (int) $user->getKey()) {
            return true;
        }

        /*
        | A guardian reads their own child's card and no one else's, and the
        | permission asked for is `Results` — the same one that gates the periodic
        | assessment, because this document is the same information gathered up.
        | A guardian who holds only `Attendance` sees the attendance alerts and
        | not the grades, which is the split that permission exists to make.
        */
        return $this->guardians
            ->childrenOf($user, GuardianPermission::Results)
            ->contains(fn (User $child): bool => (int) $child->getKey() === (int) $card->student_user_id);
    }
}
