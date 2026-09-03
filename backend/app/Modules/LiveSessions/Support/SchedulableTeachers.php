<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Whose calendar a person may write to.
 *
 * ⚠️ NOTHING ASKED THIS QUESTION UNTIL NOW, AND `sessions.manage` SITS ON THE
 * TEACHER ROLE. `ClassSessionPolicy::create(User $user)` receives the class and
 * never the profile, so it can only ask «may you schedule at all»; the named
 * `teacher_profile_uuid` was then carried from the request into
 * `ClassSession::create()` with `$actor` used for `created_by` and nothing else.
 * The one check on it — `WorkspaceRules::exists` — is satisfied by any colleague
 * BY DEFINITION, so a teacher in a shared academy could put a lesson on another
 * teacher's calendar. `created_by` records who did it; no guard prevented it.
 *
 * And it is not a display concern: since spec 014 the teacher is paid FROM
 * DELIVERY, so a session on someone's calendar becomes their teaching unit and
 * their ledger entry the moment it is taught.
 *
 * ⚠️ THE PREDICATE IS A COLUMN, NEVER A ROLE NAME. `Workspace::isOwnedBy()` is
 * the durable fact; a rule written against `tenant-owner` is one rename away from
 * guarding nothing — the lesson `AssistantScopeDirectory` already wrote down.
 *
 * ⚠️ AND NO NEW PERMISSION. A tenant permission has to be added to the role
 * arrays AND backfilled into every workspace that already has its roles seeded —
 * the runtime-catalogue family this repository has been bitten by five times. The
 * owner column answers it today, for every workspace, with nothing to seed.
 *
 * The cross-workspace case is already closed upstream: `WorkspaceRules::exists`
 * refuses a profile outside the caller's workspace before this is ever reached.
 * What is left is the within-workspace case, which is the whole defect.
 */
class SchedulableTeachers
{
    /**
     * Your own profile, or anyone's if you own the academy.
     */
    public function mayScheduleFor(User $actor, TeacherProfile $profile): bool
    {
        if ($profile->user_id === $actor->getKey()) {
            return true;
        }

        $workspace = Workspace::query()
            ->withoutGlobalScopes()
            ->find($profile->workspace_id);

        return $workspace instanceof Workspace && $workspace->isOwnedBy($actor);
    }

    /**
     * ⚠️ RAISED IN THE ACTION, NOT IN THE FORM REQUEST. The Action is the entry
     * point seeders, Filament and the API share (Constitution II); a guard in the
     * request shapes one HTTP call and nothing else.
     *
     * @throws AuthorizationException
     */
    public function assert(User $actor, TeacherProfile $profile): void
    {
        if ($this->mayScheduleFor($actor, $profile)) {
            return;
        }

        // Named, not uniform: this is not an entitlement question whose shape
        // leaks something (FR-015's family) — it is an operator being told they
        // picked the wrong person, and the fix is to pick themselves.
        throw new AuthorizationException('لا يمكنك جدولة حصة على تقويم مدرّس آخر.');
    }
}
