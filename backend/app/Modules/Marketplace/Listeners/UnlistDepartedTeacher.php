<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Listeners;

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Compliance\Events\TeacherOffboardingRequested;
use App\Modules\Marketplace\Models\TeacherProfile;

/**
 * A departing teacher stops being advertised (spec 013 · FR-035).
 *
 * ⚠️ AND THE PAID ACCESS IS UNTOUCHED, WHICH IS THE WHOLE OF FR-035. Listing and
 * entitlement are already two different mechanisms in this codebase —
 * `publiclyListed()` reads `is_publicly_listed`, `Enrollment::accessTo()` reads
 * the course tree — so a student who paid keeps every lesson for the rest of their
 * right while the public page goes. That separation is why this requirement needs
 * no new work in `Learning` at all; folding the two together, ever, would take a
 * course away from somebody who bought it.
 *
 * ⚠️ REGISTERED ON BOTH EVENTS ON PURPOSE. The contract table puts unlisting under
 * completion, and FR-035's «فوراً» reads that way — the departed teacher's content
 * stops being published the moment they leave. But an exit serves a notice period
 * of a month, and a profile still on the marketplace through it enrols NEW
 * students with somebody who is leaving. Listing again is what nobody wants, and
 * unlisting twice costs one idempotent UPDATE, so it runs at request as well.
 * `bio` and `qualifications` are deliberately NOT cleared — this person is
 * leaving, not asking to be forgotten; that is `MarketplacePersonalData::erase()`.
 */
class UnlistDepartedTeacher
{
    public function handle(TeacherOffboardingRequested|TeacherOffboardingCompleted $event): void
    {
        $offboarding = $event->offboarding;

        /*
        | `is_publicly_listed` is assigned rather than derived here, which is the
        | same thing `SuspendTeacher` and the erasure walk both do: the column is
        | the stored answer to "approved AND participating", and an exit ends the
        | participation half. The predicate shrinks, so a second pass matches
        | nothing.
        */
        TeacherProfile::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $offboarding->workspace_id)
            ->where('user_id', $offboarding->teacher_user_id)
            ->where('is_publicly_listed', true)
            ->update(['is_publicly_listed' => false, 'updated_at' => now()]);
    }
}
