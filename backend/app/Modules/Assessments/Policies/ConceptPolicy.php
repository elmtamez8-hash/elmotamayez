<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * The taxonomy a question is tagged against.
 *
 * Reading it is `BANK_VIEW`, not `QUESTIONS_MANAGE`, even though the contract
 * lists the concepts endpoint under authorship: the bank screen's concept filter
 * is unusable without the list, and every `QUESTIONS_MANAGE` holder holds
 * `BANK_VIEW` too, so the wider read gate loosens nothing for anyone.
 *
 * There is no delete. A concept with questions behind it cannot be removed
 * without either orphaning them or silently retagging them, and `concept_id` is
 * NOT NULL — so the missing ability is the answer, not an omission.
 */
class ConceptPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::BANK_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, Concept $concept): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($concept))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::BANK_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::QUESTIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Concept $concept): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($concept))->denied()) {
            return $workspaceCheck;
        }

        // The sentinel every pre-008 question was assigned to. Renaming it
        // breaks nothing structurally — the backfill matched on name once and is
        // long past — but it is the one row a teacher did not create and cannot
        // meaningfully own, and a bank whose "unclassified" bucket has been
        // renamed to a subject reads as if those questions were tagged.
        if ($concept->name === Concept::UNCLASSIFIED) {
            return Response::deny('لا يمكن تعديل الفكرة الافتراضية «غير مصنّف».');
        }

        return $user->can(Permissions::QUESTIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }
}
