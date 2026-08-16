<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\Accommodation;
use App\Modules\Assessments\Support\ApplyAccommodation;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Traits\LogsActivity;
use DomainException;

/**
 * A standing arrangement for one student (FR-053 · FR-055).
 *
 * ⚠️ IT ASKS WHETHER THE STUDENT IS THEIRS BEFORE IT WRITES. A bare uuid
 * parameter is an identity probe: pass any user's and — without this — the
 * response comes back carrying their name, which is precisely what NFR-001أ
 * forbids a teacher learning about somebody with no enrolment in their own
 * workspace. `exists:users,uuid` answers a different question and would pass.
 *
 * ⚠️ AND THE REFUSAL IS THE SAME ANSWER IN BOTH CASES. A reply that
 * distinguished "no such student" from "not your student" is the same probe in a
 * more precise form — it confirms the uuid names a real person. The controller
 * turns this into 404 for both.
 */
class GrantAccommodation extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly ApplyAccommodation $accommodations,
    ) {}

    /**
     * @throws DomainException when the student is not this workspace's to arrange for
     */
    public function handle(
        int $workspaceId,
        User $actor,
        User $student,
        int $extraTimePct,
        int $extendedDays,
        string $reason,
    ): Accommodation {
        if (! $this->enrollments->hasActiveEnrollmentInWorkspace($student, $workspaceId)) {
            throw new DomainException('لا يوجد طالبٌ بهذا المعرّف.');
        }

        if (trim($reason) === '') {
            throw new DomainException('التسهيل يُسجَّل بسببه.');
        }

        if ($extraTimePct < 0 || $extraTimePct > 200 || $extendedDays < 0 || $extendedDays > 30) {
            throw new DomainException('قيمة التسهيل خارج الحدود.');
        }

        // One standing arrangement per student per workspace: a second row would
        // make "which one applies" a question, and the answer would differ
        // between the deadline computer and the attempt issuer.
        $accommodation = Accommodation::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'student_user_id' => $student->getKey(),
            ],
            [
                'extra_time_pct' => $extraTimePct,
                'extended_days' => $extendedDays,
                'reason' => $reason,
                'granted_by' => $actor->getKey(),
                'revoked_at' => null,
            ],
        );

        // The reader memoises per request, and this request has just changed the
        // answer it holds.
        $this->accommodations->forget((int) $student->getKey());

        $this->logActivity('accommodation.granted', $accommodation, [
            'extra_time_pct' => $extraTimePct,
            'extended_days' => $extendedDays,
            'reason' => $reason,
        ]);

        return $accommodation;
    }

    /**
     * Withdraw it — stamped, never deleted. FR-055 asks who granted it and when,
     * and a deleted row answers neither question the day somebody asks.
     */
    public function revoke(Accommodation $accommodation, User $actor): Accommodation
    {
        $accommodation->forceFill(['revoked_at' => now()])->save();

        $this->accommodations->forget((int) $accommodation->student_user_id);

        $this->logActivity('accommodation.revoked', $accommodation, [
            'by' => $actor->getKey(),
        ]);

        return $accommodation->refresh();
    }
}
