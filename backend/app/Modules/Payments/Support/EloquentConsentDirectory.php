<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Payments\Actions\RecordTermsConsent;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Shared\Contracts\ConsentDirectory;
use InvalidArgumentException;

/**
 * The consent record, exposed to the rest of the product (spec 013).
 *
 * ⚠️ THE BINDING IS TO THIS CLASS, NOT TO {@see ConsentRegistry}, and an earlier
 * draft said otherwise in the same breath as "zero changes in Payments". Both
 * cannot be true: the registry is a READER — it has no `record()` and never did —
 * and the only writer is {@see RecordTermsConsent}. Binding the contract straight
 * to the registry would satisfy four methods and leave the fifth unimplementable.
 *
 * Same shape as the shipped `EloquentEnrollmentDirectory`,
 * `EloquentApprovedRateDirectory` and `EloquentGuardianDirectory`: the module that
 * owns the table owns the adapter, and the caller sees only the interface.
 *
 * ⚠️ AND THE DOCUMENT CROSSES THE BOUNDARY AS A STRING. The enum lives here; a
 * contract in `Shared` importing it would restore exactly the coupling the
 * contract exists to cut. It is resolved back to the enum on this side, where it
 * belongs — and an unknown name THROWS rather than defaulting, because a default
 * would make a typo silently ask about the payment terms instead of the data
 * agreement.
 */
final class EloquentConsentDirectory implements ConsentDirectory
{
    public function __construct(
        private readonly ConsentRegistry $registry,
        private readonly RecordTermsConsent $recorder,
    ) {}

    public function currentVersion(string $document): string
    {
        return $this->registry->currentVersion($this->documentFrom($document));
    }

    public function hasCurrent(User $subject, string $document): bool
    {
        return $this->registry->has($subject, $this->documentFrom($document));
    }

    public function everAccepted(User $subject, string $document): bool
    {
        return $this->registry->everAccepted($subject, $this->documentFrom($document));
    }

    public function consentedCategories(User $subject, string $document): ?array
    {
        return $this->registry->consentedCategories($subject, $this->documentFrom($document));
    }

    public function record(
        User $signer,
        User $subject,
        string $document,
        array $categories,
        string $ipAddress,
        ?string $userAgent,
        bool $granted = true,
    ): void {
        $this->recorder->handle(
            $signer,
            $subject,
            $this->documentFrom($document),
            $ipAddress,
            $userAgent,
            $categories,
            $granted,
        );
    }

    /**
     * ⚠️ THROWS ON AN UNKNOWN NAME. `tryFrom()` with a fallback would turn a typo
     * into a question about the WRONG DOCUMENT — asking whether someone signed the
     * payment terms when the caller meant the data agreement, and answering with
     * confidence. The shipped guard on the enum argument makes the same point:
     * "a parameter with a default is one forgotten argument away from making them
     * the same thing".
     */
    private function documentFrom(string $document): ConsentDocument
    {
        $resolved = ConsentDocument::tryFrom($document);

        if ($resolved === null) {
            throw new InvalidArgumentException("وثيقةُ موافقةٍ غيرُ معروفة: {$document}");
        }

        return $resolved;
    }
}
