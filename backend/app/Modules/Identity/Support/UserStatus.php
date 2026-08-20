<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * Whether an account may be used (spec 013 · FR-003 · FR-009ب · SC-017).
 *
 * ⚠️ THE CONCEPT DID NOT EXIST BEFORE THIS PHASE, AND THE SPEC ASSUMED IT DID.
 * `users.status` has a column and a default of `'active'` — and nothing in
 * Identity ever wrote it and nothing ever read it. `RegisterStudent` produced a
 * fully usable account in one save, with no age and no guardian. So `FR-003`,
 * `FR-009ب` and `SC-017` were all written against a transition that had no
 * implementation on either side, and 013 creates the state itself.
 *
 * ⚠️ AND THERE IS NO BACKFILL. Every existing account stays `active`: a phase that
 * introduced a consent gate and applied it retroactively would lock out the entire
 * current student body on deploy, over a document none of them had been shown.
 * The gate applies from here on.
 *
 * ⚠️ THE STATE LIVES IN `Identity`, NOT IN `Compliance`. An Action in the
 * compliance module writing `users.status` is precisely the violation the whole
 * `PersonalDataOwner` contract was built to avoid. Compliance asks
 * `ConsentDirectory` and fires an event; Identity writes.
 */
enum UserStatus: string
{
    case Active = 'active';

    /**
     * A minor whose guardian has not yet consented to processing their data.
     *
     * ⚠️ THE SIGN-IN PATH REFUSES THIS WITHOUT MINTING A TOKEN AT ALL. Minting one
     * and then restricting what it can reach means any flaw in the restriction is
     * a complete sign-in — which is the reasoning `/auth/login` already applies to
     * a correct password on a two-factor account.
     */
    case PendingGuardianConsent = 'pending_guardian_consent';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'مفعَّل',
            self::PendingGuardianConsent => 'بانتظار موافقة وليّ الأمر',
        };
    }

    public function canSignIn(): bool
    {
        return $this === self::Active;
    }
}
