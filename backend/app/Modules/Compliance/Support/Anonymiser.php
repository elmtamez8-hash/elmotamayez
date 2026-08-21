<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Modules\Identity\Support\IdentityPersonalData;

/**
 * Neutral values that replace a person, irreversibly (FR-022 · SC-008).
 *
 * ⚠️ FIXED VALUES AND A SEVERED IDENTIFIER — NEVER A HASH. Hashing looks like the
 * careful choice and is the opposite of one here: a Qatari mobile number has about
 * eight variable digits, so a hashed phone is brute-forced back in minutes on a
 * laptop. A hashed name is worse, because the platform's own user table is the
 * dictionary. A hash is a reversible value wearing a one-way name, and it would
 * satisfy an auditor reading the column while satisfying nothing else.
 *
 * ⚠️ AND THE EMAIL CARRIES THE ROW ID, WHICH IS NOT A LEAK. `users.email` is
 * UNIQUE, so a constant would make the second erasure on the platform fail with a
 * constraint violation — and the id is already the primary key of the row the
 * value sits in. It identifies the ROW, which is what must survive; it says
 * nothing about the PERSON, which is what must not.
 */
final class Anonymiser
{
    /** What a name becomes. Readable, so a screen showing it is not blank. */
    public function name(): string
    {
        return 'مستخدم محذوف';
    }

    /**
     * A unique, obviously-synthetic address.
     *
     * The `anonymised+` prefix is also how {@see IdentityPersonalData}
     * recognises an already-erased row — so a repeated erasure is a no-op rather
     * than a second pass that reports work it did not do.
     */
    public function email(int|string $rowId): string
    {
        return 'anonymised+'.$rowId.'@deleted.invalid';
    }
}
