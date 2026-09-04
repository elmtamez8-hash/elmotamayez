<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A teacher completed step one of the wizard — the account now exists.
 *
 * Spec 025 · FR-001. Tenancy listens and creates the implicit workspace, which is
 * why this crosses no module boundary: Constitution III forbids Marketplace
 * calling `CreateWorkspace` directly, and an event with a synchronous listener is
 * the same shape `WorkspaceCreated → SeedDefaultRoles` already runs on.
 *
 * ⚠️ A DEDICATED EVENT, NOT `Illuminate\Auth\Events\Registered` — which this same
 * Action already fires one line above the dispatch. The student and parent paths
 * fire `Registered` too, so a shared listener would need a `platform_role`
 * predicate: a SECOND spelling of "is this a teacher?", a question the product
 * answers in exactly one place. Being teacher-only by construction is also what
 * satisfies FR-004 for free — the student path, the parent path and account
 * creation from `/admin` never dispatch this at all, so there is no rule to
 * forget and no branch to test.
 */
class TeacherRegistered
{
    use Dispatchable;

    public function __construct(public readonly User $teacher) {}
}
