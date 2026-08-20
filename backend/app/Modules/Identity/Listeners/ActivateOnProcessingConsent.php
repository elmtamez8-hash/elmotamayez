<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Actions\ActivateStudentAccount;
use App\Modules\Payments\Events\ProcessingConsentGranted;
use DomainException;

/**
 * Consent recorded in `Payments` → account opened in `Identity` (FR-003).
 *
 * ⚠️ THE LISTENER IS THE WHOLE BRIDGE, and it runs in the subscribing module. The
 * alternative — `RecordTermsConsent` calling an Identity Action — is a write to
 * another module's aggregate from inside a third one's transaction, which
 * Constitution III forbids and which would also make the consent record fail if
 * account activation ever did.
 *
 * ⚠️ AND THE REFUSAL IS SWALLOWED DELIBERATELY. `ActivateStudentAccount` throws
 * when the consent is not currently valid — which happens legitimately here, when
 * a SECOND authorised guardian has refused more recently (R6, refusal wins). That
 * is not an error in recording the first guardian's decision, and letting it
 * escape would roll back a consent row that must be kept: the table is a log of
 * decisions, and the disagreement is exactly what it is for.
 */
class ActivateOnProcessingConsent
{
    public function __construct(private readonly ActivateStudentAccount $activate) {}

    public function handle(ProcessingConsentGranted $event): void
    {
        try {
            $this->activate->handle($event->student);
        } catch (DomainException) {
            // Refusal wins, or the version in force moved on. Both are states the
            // consent record is meant to hold, not failures of writing it.
        }
    }
}
