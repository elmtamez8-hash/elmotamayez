<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Contracts\PersonalDataOwner;

/**
 * What erasure does to one row (spec 013 · FR-020 … FR-023).
 *
 * ⚠️ IN `Shared`, NOT IN `Compliance`, and the reason is arithmetic: this is a
 * parameter of a contract THIRTEEN modules implement, and thirteen modules may
 * not be made to import an enum owned by a fourteenth. The precedent carries the
 * rule in its own docblock — {@see GuardianPermission} "lives in Shared rather
 * than in Identity because it is the shared vocabulary of two modules".
 *
 * ⚠️ AND THE IMPLEMENTOR NEVER CHOOSES IT. `erase()` RECEIVES the mode; it does
 * not decide one. Erasure is three grades rather than one because the law is
 * three grades — a financial record must survive with its subject detached, a
 * publicly verifiable certificate must survive intact, and a lesson-progress row
 * must simply go. A module that picked its own grade would be a module deciding
 * what the platform is legally obliged to keep.
 *
 * @see PersonalDataOwner
 */
enum ErasureMode: string
{
    /** The row goes. Nothing about the person survives it. */
    case Delete = 'delete';

    /**
     * The row stays and stops pointing at anyone.
     *
     * ⚠️ NOT HASHING — fixed neutral values and a severed identifier. A Qatari
     * mobile number has a small enough space to be brute-forced back out of a
     * hash in minutes, so a hashed phone is a reversible phone wearing a
     * one-way name. Same for a name against the platform's own user table.
     */
    case Anonymise = 'anonymise';

    /**
     * The row is untouched, because erasing it would destroy something the
     * subject is entitled to or the platform is obliged to keep.
     *
     * The certificate is the case that forced this third value to exist: its
     * code is publicly verifiable, so nulling the student leaves a certificate
     * that verifies as belonging to nobody, deleting it destroys a credential
     * the student earned, and anonymising the joined name silently rewrites a
     * public statement of fact.
     */
    case Retain = 'retain';
}
