<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Models\User;
use App\Modules\Payments\Models\TermsConsent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A guardian (or an adult student) consented to data processing (spec 013).
 *
 * ⚠️ AN EVENT RATHER THAN A CALL, because the writer and the reader are in
 * different modules and neither may name the other's Action (Constitution III).
 * `Payments` owns the consent record and knows nothing about account status;
 * `Identity` owns account status and must not query `terms_consents`.
 *
 * ⚠️ AND IT CARRIES THE SUBJECT, NOT JUST THE ROW. The listener acts on the
 * student — who is frequently NOT the signer — and re-deriving that from the row
 * in every listener is one `user_id`/`student_user_id` mix-up away from activating
 * the parent's account instead of the child's.
 *
 * ⚠️ IT FIRES ONLY FOR A GRANT. A refusal is recorded in the same table by the
 * same Action and is a legitimate row; announcing it here would make every
 * listener responsible for checking which kind it was, and the first one to forget
 * would activate an account whose guardian said no.
 */
class ProcessingConsentGranted
{
    use Dispatchable;

    public function __construct(
        public readonly User $student,
        public readonly User $signer,
        public readonly TermsConsent $consent,
    ) {}
}
