<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;

/**
 * A student names the guardian who must consent for them — the pending row that
 * IS the invitation, and the notice that tells the guardian it is waiting.
 *
 * ⚠️ ONE SPELLING, TWO CALLERS. `RegisterStudent` writes this at signup when the
 * guardian's number already belongs to a verified account, and
 * `InviteGuardianOnContactVerified` writes the very same row later, the moment a
 * guardian who signed up AFTER the child proves they own that number. Until this
 * class existed the second caller did not, and a parent who registered after their
 * child was never linked at all: `student_profiles.guardian_contact` had a writer
 * and no reader. Two copies of this body would be the two-spellings defect the
 * tree records again and again — the live-rows rule below already drifted once
 * between this file's ancestor and `LinkGuardian::alreadyLinked()`.
 *
 * ⚠️ `Pending` grants nothing — `EloquentGuardianDirectory` asks `->active()` in
 * all three of its methods — so the row is an invitation WITHOUT AUTHORITY BY
 * CONSTRUCTION. And it is created from the STUDENT's side (`requested_by` is the
 * student), so the party who settles it is the guardian (spec 030 · FR-002).
 */
final class GuardianInvitation
{
    public function __construct(private readonly DispatchNotification $notifications) {}

    /** True when a new pending row was written, false when a live one already existed. */
    public function invite(User $student, User $guardian): bool
    {
        /*
        | ⚠️ LIVE ROWS ONLY — a refused link may be requested again (spec 030 ·
        | FR-012), and matching a dead row here would leave the guardian untold.
        | This is the friendly check; the guard is `psr_live_unique`.
        */
        $existing = ParentStudentRelation::query()
            ->where('guardian_user_id', $guardian->getKey())
            ->where('student_user_id', $student->getKey())
            ->whereIn('status', [RelationStatus::Pending->value, RelationStatus::Active->value])
            ->exists();

        if ($existing) {
            return false;
        }

        $name = trim($student->first_name.' '.$student->last_name);

        ParentStudentRelation::query()->create([
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => $student->getKey(),
            'student_name' => $name,
            'relation_type' => RelationType::Parent->value,
            /*
            | A `pending` row grants nothing, so typing a contact hands over no
            | authority; what the guardian holds once they accept is
            | `permissionsFor()`.
            */
            'permissions' => $this->permissionsFor($student),
            'status' => RelationStatus::Pending->value,
            'requested_by_user_id' => $student->getKey(),
        ]);

        $this->notifications->handle(new NotificationRequest(
            recipient: $guardian,
            type: NotificationType::GuardianConsentRequired,
            variables: ['student_name' => $name],
            // `NotificationRow` wraps a feed row in a link only when `action_url`
            // is set; without it the one notice that exists to be acted on leads
            // nowhere.
            actionUrl: '/family',
            subject: $student,
        ));

        return true;
    }

    /**
     * ⚠️ A MINOR'S GUARDIAN FOLLOWS THEM FROM THE START (owner decision
     * 2026-10-23). With `DataRights` alone, a parent who consented saw «غير ممنوح
     * لك» on every card and nothing told the child to widen it. These still grant
     * nothing until the guardian accepts (`Pending`), and the student may narrow
     * them afterwards through `UpdateRelationPermissions`. `Messages` stays the
     * student's to give: it lets the guardian WRITE as the child.
     *
     * @return list<string>
     */
    private function permissionsFor(User $student): array
    {
        if ($student->status !== UserStatus::PendingGuardianConsent->value) {
            return [GuardianPermission::DataRights->value];
        }

        return array_map(fn (GuardianPermission $p): string => $p->value, [
            GuardianPermission::DataRights,
            GuardianPermission::Attendance,
            GuardianPermission::Schedule,
            GuardianPermission::Results,
            GuardianPermission::Payments,
            GuardianPermission::AcademicWarnings,
        ]);
    }
}
