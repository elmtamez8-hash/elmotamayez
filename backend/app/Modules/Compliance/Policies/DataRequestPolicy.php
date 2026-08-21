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

    /**
     * The officer's queue.
     *
     * ⚠️ A SEPARATE ABILITY FROM `view`, because they answer different questions:
     * `view` asks whether this person may read THIS row, and the queue asks whether
     * they may read the platform's. A `viewAny` that fell back to row ownership
     * would hand every student a list endpoint filtered by a query they do not
     * control — the shape `WorkspaceScope` already fails to guard here, since
     * `data_requests` carries no tenant column and a student belongs to no
     * workspace.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::COMPLIANCE_REQUESTS_EXECUTE);
    }

    /**
     * Running it — the act that writes `executed_by_user_id` (FR-026).
     *
     * ⚠️ THE SUBJECT MAY NOT EXECUTE THEIR OWN ERASURE, WHICH IS NOT A SLIGHT. The
     * announced execution period in FR-019 exists so that a destruction nobody can
     * undo is looked at by a person who can weigh a legal hold against it. A
     * self-service button would make the officer endpoints decorations and the
     * notice period a number in a document.
     */
    public function execute(User $user, DataRequest $request): bool
    {
        return $user->can(Permissions::COMPLIANCE_REQUESTS_EXECUTE);
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
