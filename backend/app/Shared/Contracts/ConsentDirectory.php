<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * The consent record, reached from outside the module that owns it
 * (spec 013 · FR-003 · FR-005 … FR-008).
 *
 * ⚠️ NOTHING NEW IS BUILT BEHIND THIS. `terms_consents`, `ConsentRegistry` and
 * `RecordTermsConsent` have been running since 006, and `ConsentDocument` already
 * carries `DataProcessing` with a comment saying its text and its erasure rules
 * belong to spec 013. A second consent entity would be two systems answering one
 * question, which is how they come to disagree.
 *
 * ⚠️ AND IT IS BOUND TO `EloquentConsentDirectory`, NEVER TO `ConsentRegistry`.
 * The registry has no writer — the only one is the Action — so binding to it
 * would satisfy the reads and leave `record()` unimplementable. Same shape as
 * `EloquentEnrollmentDirectory` and `EloquentGuardianDirectory`.
 *
 * ⚠️ THE DOCUMENT IS A STRING, NOT THE ENUM, AND CARRIES NO DEFAULT. The enum
 * lives in `Payments`; a contract in `Shared` importing it would restore the
 * coupling it exists to cut. And a defaulted argument is one forgotten call away
 * from making two different documents the same document — the guard
 * `RecordTermsConsent` already writes down for itself.
 */
interface ConsentDirectory
{
    /** The version of this document currently in force. */
    public function currentVersion(string $document): string;

    /**
     * Whether the subject is covered by a valid consent to the CURRENT version.
     *
     * ⚠️ ITS MEANING IS FIXED AND MUST NOT DRIFT. Billing reads this for the
     * credit ceiling, where the question is "did they sign the terms in force".
     * It is NOT "are they still happy with every category" — that is
     * {@see self::consentedCategories()}, and folding the two together would
     * silently change what a withheld student is withheld for.
     *
     * Since 013 it also answers false when an authorised guardian has REFUSED
     * more recently than any grant: refusal wins, because refusal is the
     * reversible position and exposure is not.
     */
    public function hasCurrent(User $subject, string $document): bool;

    /** Whether any version was ever accepted — used to tell "new" from "lapsed". */
    public function everAccepted(User $subject, string $document): bool;

    /**
     * The categories carried by the LATEST consent row for the current version.
     *
     * ⚠️ `null` AND `[]` ARE DIFFERENT ANSWERS. `null` is "this document has no
     * categories at all" — every deferred-payment row ever written. `[]` is
     * "they were asked, and consented to none of it".
     *
     * @return list<string>|null
     */
    public function consentedCategories(User $subject, string $document): ?array;

    /**
     * Write a decision. Always a NEW row — a withdrawal is a row, a refusal is a
     * row, and nothing is ever updated in place: the table is a record of
     * decisions taken at moments, and editing one destroys the evidence of what
     * was actually agreed. The same argument `LedgerEntry` makes for itself.
     *
     * @param  list<string>  $categories  the COMPLETE set, never a diff — a diff
     *                                    applied to state read a second ago is the
     *                                    lost update, and this table decides
     *                                    whether a child's data may be processed
     */
    public function record(
        User $signer,
        User $subject,
        string $document,
        array $categories,
        string $ipAddress,
        ?string $userAgent,
        bool $granted = true,
    ): void;
}
