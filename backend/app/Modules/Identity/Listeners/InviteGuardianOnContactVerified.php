<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Support\GuardianInvitation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Notifications\Events\ContactVerified;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A guardian who signs up AFTER their child is found the moment they prove the
 * number the child named.
 *
 * ⚠️ `student_profiles.guardian_contact` HAD A WRITER AND NO READER. A minor names
 * a guardian's number at signup; `RegisterStudent` resolves it against VERIFIED
 * contacts only, and when nobody has proved that number yet it does nothing — the
 * contact stays on the profile and, until this listener, nothing ever looked at it
 * again. So a parent who registered second was never linked, and the child's
 * account sat in `pending_guardian_consent` with no route out.
 *
 * ⚠️ WHY HERE AND NOT AT PARENT REGISTRATION. The number a parent types at signup
 * is a string nobody confirmed — matching it would hand whoever typed a child's
 * guardian number an invitation over that child's data. `ContactVerified` is the
 * first moment the number is evidence, which is the rule `GuardianContactResolver`
 * already follows for the signup-time match. The row written is the same one
 * (`GuardianInvitation`): pending, `DataRights` only, requested by the STUDENT, so
 * the guardian still has to accept it and nothing is granted before they do.
 *
 * Two narrowings the signup-time path does not have, both conservative:
 *
 *  - the verifier must be a PARENT account. A teacher or another student who
 *    happens to prove a number a child mistyped is not invited to be anyone's
 *    guardian.
 *  - a child who already has a live parent link is skipped. A stale number on an
 *    old profile must not spawn a second parent request months later.
 *
 * Queued and after-commit: a failure here must never turn a successful
 * verification into an error on the screen that confirmed it.
 */
class InviteGuardianOnContactVerified implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    /** A bound, not a feature: one number naming more children than this is not a family. */
    private const MAX_CHILDREN = 20;

    public function __construct(private readonly GuardianInvitation $invitation) {}

    public function handle(ContactVerified $event): void
    {
        $guardian = User::query()->whereKey($event->userId)->first();

        if ($guardian === null || $guardian->platform_role !== PlatformRole::Parent) {
            return;
        }

        $profiles = StudentProfile::query()
            ->where('guardian_contact', trim($event->contactValue))
            ->where('user_id', '!=', $guardian->getKey())
            ->with('user')
            ->orderBy('id')
            ->limit(self::MAX_CHILDREN)
            ->get();

        foreach ($profiles as $profile) {
            $student = $profile->user;

            if (! $student instanceof User || $student->platform_role !== PlatformRole::Student) {
                continue;
            }

            if ($this->hasLiveParent($student)) {
                continue;
            }

            $this->invitation->invite($student, $guardian);
        }
    }

    private function hasLiveParent(User $student): bool
    {
        return ParentStudentRelation::query()
            ->forStudent($student)
            ->where('relation_type', RelationType::Parent->value)
            ->whereIn('status', [RelationStatus::Pending->value, RelationStatus::Active->value])
            ->exists();
    }
}
