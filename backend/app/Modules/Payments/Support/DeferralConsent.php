<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Payments\Models\TermsConsent;

/**
 * Has this student — or an authorised guardian on their behalf — agreed to owe?
 *
 * FR-048 makes the recorded agreement the gate on any ceiling above zero, and
 * Q-9 starts every student at zero because of it. One reader, two callers with
 * two different reactions: the manual override REFUSES without it, and the
 * automatic raise simply does not raise. Two copies of the question would drift,
 * and the direction they would drift is a ceiling granted where no agreement
 * exists.
 *
 * ⚠️ VERSION CURRENCY IS NOT ASKED HERE YET. FR-049 says a new version of the
 * terms cannot inherit an old acceptance, and the version that is current is a
 * thing US9 introduces along with the Action that records one. Asking "is there
 * a consent at all" is the check that can be true today; pretending to check the
 * version against a constant nobody maintains would be worse than not checking.
 */
class DeferralConsent
{
    public const DOCUMENT = 'deferred_payment_terms';

    public function recordedFor(User $student): bool
    {
        return TermsConsent::query()
            ->where('student_user_id', $student->getKey())
            ->where('document', self::DOCUMENT)
            ->exists();
    }
}
