<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Policies;

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;

/**
 * Who may read a data request and download what it produced (FR-015 · FR-018).
 *
 * ⚠️ IT ASKS `GuardianDirectory`, AND `ParentStudentRelationPolicy` IS FORBIDDEN
 * HERE. That policy receives a RELATION ROW, not a (guardian, student) pair, so
 * asking it the question this class answers is circular — you would have to find
 * the relation first, which is the thing being authorised. It also does not check
 * the relation's `status`, so a `pending` link (which any user can create for any
 * student uuid) and a `revoked` one (a family that removed somebody) both pass;
 * and its third branch is built for TEACHERS, on a permission every teacher and
 * assistant holds.
 *
 * ⚠️ AND AUTHORISATION IS RE-ASKED AT DOWNLOAD TIME, NOT INHERITED FROM CREATION.
 * The archive outlives the request by hours, and a guardianship revoked in between
 * is exactly the case FR-023 is about. The frozen `granted_scope` limits what went
 * INTO the file; this decides whether the person may still have the file at all —
 * two different questions, and answering only the first leaves a live link in the
 * hands of somebody the family has removed.
 */
class DataRequestPolicy
{
    public function __construct(private readonly GuardianDirectory $guardians) {}

    /**
     * Anyone signed in may ASK. Whose data they may ask about is
     * {@see CreateDataRequest}'s decision, and it
     * refuses with one undifferentiated message so this endpoint cannot be used to
     * confirm that a uuid belongs to a real account.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, DataRequest $request): bool
    {
        return $this->owns($user, $request) || $user->can(Permissions::COMPLIANCE_REQUESTS_EXECUTE);
    }

    /**
     * The officer is deliberately ABSENT from this one.
     *
     * `compliance.requests.execute` is the authority to RUN a request and to see
     * that it ran; it is not a standing entitlement to read the contents of any
     * child's record on the platform. An officer who needs the archive itself has
     * a reason, and a reason belongs in a written access decision rather than in a
     * permission everybody on the team holds.
     */
    public function download(User $user, DataRequest $request): bool
    {
        return $this->owns($user, $request) && $request->isDownloadable();
    }

    private function owns(User $user, DataRequest $request): bool
    {
        if ((int) $request->subject_user_id === (int) $user->getKey()) {
            return true;
        }

        // The person who ASKED is not automatically the person who may still read.
        // Both halves are required: the recorded requester, and a guardianship that
        // is active right now.
        if ((int) $request->requested_by_user_id !== (int) $user->getKey()) {
            return false;
        }

        $subject = $request->subject;

        return $subject !== null
            && $this->guardians->isAuthorised($user, $subject, GuardianPermission::DataRights);
    }
}
