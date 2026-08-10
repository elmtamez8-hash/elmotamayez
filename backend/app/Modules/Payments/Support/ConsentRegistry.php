<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * Has this person agreed — to THIS document, in ITS CURRENT VERSION?
 *
 * One reader, several callers with different reactions: the manual override
 * REFUSES without it, the automatic raise simply does not raise, and the ledger's
 * floor collapses to zero. Two copies of the question would drift, and the
 * direction they would drift is deferral running on an agreement nobody gave.
 *
 * ⚠️ THE VERSION IS PART OF THE QUESTION (FR-049). Without it, publishing new
 * terms leaves every old acceptance silently inherited by text the person never
 * read — which is the one thing a versioned document exists to prevent. Because
 * the check is derived rather than stored, a bump takes effect on the next
 * booking and a re-signature restores the ceiling on the one after that; nothing
 * has to walk the table on either side.
 *
 * ⚠️ AND THE DOCUMENT IS NAMED BY THE CALLER (FR-050). There is no default: a
 * deferred-payment agreement is not a data-processing agreement in either
 * direction, and a parameter with a default is one forgotten argument away from
 * making them the same thing.
 */
class ConsentRegistry
{
    /**
     * The version of this document that is in force right now.
     *
     * `platform_settings` first, config as the fallback — an operator publishes
     * new terms from the panel, and a version that can only change by shipping
     * code is a version nobody ever publishes.
     */
    public function currentVersion(ConsentDocument $document): string
    {
        $key = 'consents.versions.'.$document->value;

        return (string) PlatformSettings::get($key, config($key, '1.0'));
    }

    /**
     * Whether this person has EVER accepted this document, in any version.
     *
     * A different question from {@see self::has()}, and the difference is load
     * bearing: the opening ceiling is granted on a first acceptance and must not
     * be granted again when new terms are published. Asking the version-aware
     * question there would make every republish a first acceptance, and the
     * ceiling FR-040 took away would come back with the next version of the text.
     */
    public function everAccepted(User $student, ConsentDocument $document): bool
    {
        return TermsConsent::query()
            ->where('student_user_id', $student->getKey())
            ->where('document', $document->value)
            ->exists();
    }

    public function has(User $student, ConsentDocument $document): bool
    {
        $id = (int) $student->getKey();

        return $this->forStudentIds([$id], $document)[$id] ?? false;
    }

    /**
     * The same question for a list, in one query.
     *
     * The ledger reads the floor for a whole panel of balances at a time
     * ({@see WithholdingReader}), and asking per row is the N+1 that class exists
     * to prevent.
     *
     * @param  list<int>  $studentIds
     * @return array<int, bool> keyed by student id; ids with no consent are absent
     */
    public function forStudentIds(array $studentIds, ConsentDocument $document): array
    {
        if ($studentIds === []) {
            return [];
        }

        return TermsConsent::query()
            ->whereIn('student_user_id', $studentIds)
            ->where('document', $document->value)
            ->where('version', $this->currentVersion($document))
            ->pluck('student_user_id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
    }
}
