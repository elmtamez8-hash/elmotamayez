<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\UnlockExemption;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Traits\LogsActivity;
use DomainException;

/**
 * One student let past the condition on one session (FR-040).
 *
 * ⚠️ THE REASON IS MANDATORY. An exemption with no stated cause is
 * indistinguishable from a mistake six months later — and the person asking will
 * be a parent wanting to know why their child was treated differently, or the
 * teacher who no longer remembers.
 *
 * ⚠️ AND IT ASKS WHETHER THE STUDENT IS THEIRS BEFORE IT WRITES, on the same
 * grounds as the accommodation: a bare uuid parameter is an identity probe whose
 * answer comes back carrying somebody's name (NFR-001أ). The refusal is
 * indistinguishable from "no such student", because a distinct reply for "not
 * yours" confirms the uuid names a real account.
 */
class GrantUnlockExemption extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    /**
     * @throws DomainException when the student is not this workspace's, or no reason was given
     */
    public function handle(int $workspaceId, int $classSessionId, User $actor, User $student, string $reason): UnlockExemption
    {
        if (! $this->enrollments->hasActiveEnrollmentInWorkspace($student, $workspaceId)) {
            throw new DomainException('لا يوجد طالبٌ بهذا المعرّف.');
        }

        if (trim($reason) === '') {
            throw new DomainException('الاستثناء يُسجَّل بسببه.');
        }

        $exemption = UnlockExemption::updateOrCreate(
            [
                'class_session_id' => $classSessionId,
                'student_user_id' => $student->getKey(),
            ],
            [
                'workspace_id' => $workspaceId,
                'reason' => $reason,
                'granted_by' => $actor->getKey(),
            ],
        );

        $this->logActivity('unlock.exemption_granted', $exemption, [
            'class_session_id' => $classSessionId,
            'student_user_id' => $student->getKey(),
            'reason' => $reason,
        ]);

        return $exemption;
    }
}
