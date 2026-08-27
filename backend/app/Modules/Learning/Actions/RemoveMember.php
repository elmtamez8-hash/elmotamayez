<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Support\CohortMembershipWriter;
use App\Modules\Learning\Support\PendingTransfer;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * The teacher takes somebody out of a group and leaves them in none (FR-028ط).
 *
 * ⚠️ IT DOES NOT TOUCH THE ENROLMENT, AND THAT IS THE WHOLE DISTINCTION. The
 * student paid for the COURSE; the group is a timetable. Removing them from the
 * Saturday run must not repossess what they bought — the curriculum gate then
 * asks them to pick a group again, and the safety valve opens the course
 * completely if there is none they can pick.
 *
 * ⚠️ AND THE SEAT COMES BACK, because the place is genuinely free again — the
 * counter is the number of people in the room, and a removal that left it where
 * it was would shrink the group by one for ever with nothing to say why.
 */
class RemoveMember extends Action
{
    public function handle(CohortMembership $membership, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($membership, $actor, $reason): void {
            CohortMembershipWriter::closeCurrent(
                $membership,
                CohortMembershipEvent::REMOVED,
                $actor,
                $reason,
            );

            PendingTransfer::drop(
                (int) $membership->course_id,
                (int) $membership->student_user_id,
                $actor,
                'أخرجك المدرّس من المجموعة.',
            );
        });
    }
}
