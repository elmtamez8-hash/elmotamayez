<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Modules\Payments\Support\CreditAccounts;
use App\Shared\Actions\Action;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Someone agrees, and the record of it is the whole point (FR-049 · SC-014).
 *
 * What is stored is not "yes": it is WHO said it, ABOUT WHOM, WHEN, FROM WHERE,
 * and TO WHICH VERSION of the text. A boolean column would answer the only
 * question nobody asks in a dispute.
 *
 * ⚠️ THE AUTHORISATION IS PROVED BEFORE THE WRITE, and it is not the ordinary
 * "is this my child". A guardian may sign for their student only with
 * `GuardianPermission::Payments` — the guardian entitled to attendance news has
 * no business committing that child to a debt. Without the check any account
 * could sign a legal document in someone else's name, and the response would
 * confirm the identifier belongs to a real person.
 *
 * ⚠️ THE VERSION IS READ FROM THE REGISTRY, NEVER TAKEN FROM THE REQUEST. A
 * client that chooses its own version number could accept superseded terms for
 * ever, which is FR-049 inverted with extra steps.
 *
 * ⚠️ AND THE OPENING CEILING IS GRANTED HERE, ONCE — on the FIRST acceptance of
 * the deferred-payment terms and never on a re-acceptance of a new version. A
 * grant that ran on every signature would restore the ceiling of a student FR-040
 * had demoted, the next time the terms were republished. Their way back is the
 * earning ladder or the platform's own hand, both of which leave a record.
 */
class RecordTermsConsent extends Action
{
    public function __construct(
        private readonly ConsentRegistry $consent,
        private readonly GuardianDirectory $guardians,
        private readonly CreditAccounts $accounts,
        private readonly BillingSettings $settings,
        private readonly SetCreditLimit $limits,
    ) {}

    public function handle(
        User $signer,
        User $student,
        ConsentDocument $document,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): TermsConsent {
        if (! $this->maySignFor($signer, $student)) {
            // RuntimeException, which the controller answers as 403 — the same
            // answer it gives for a student uuid that matches nobody. Two
            // different refusals with one response, so the endpoint cannot be
            // used to discover who exists.
            throw new RuntimeException('لا يحقّ لك التوقيع نيابةً عن هذا الطالب.');
        }

        // Asked BEFORE the row is written, or the answer is always "yes" and the
        // opening grant never fires. And asked with `everAccepted`, not the
        // version-aware `has`: under that one every republish is a first
        // acceptance, and the ceiling FR-040 took away comes back with the next
        // version of the text.
        $isFirst = ! $this->consent->everAccepted($student, $document);

        $consent = TermsConsent::query()->create([
            'user_id' => $signer->getKey(),
            'student_user_id' => $student->getKey(),
            'document' => $document->value,
            'version' => $this->consent->currentVersion($document),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'consented_at' => now(),
        ]);

        if ($isFirst && $document === ConsentDocument::DeferredPaymentTerms) {
            // `afterCommit` so that a caller who wraps this in a transaction
            // announces nothing that could still roll back. With no transaction
            // open — which is the HTTP path — Laravel runs the callback inline,
            // and that is the behaviour the endpoint returns its state from.
            DB::afterCommit(fn () => $this->grantOpeningLimit($student));
        }

        return $consent;
    }

    /**
     * Whether the signer is the student, or a guardian authorised for payments.
     *
     * Guardianship is asked through the directory rather than by querying
     * `parent_student_relations` — Payments does not know that table exists
     * (Constitution III), and the relation is platform-owned with no workspace
     * scope to fall back on if it did.
     */
    private function maySignFor(User $signer, User $student): bool
    {
        if ($signer->getKey() === $student->getKey()) {
            return true;
        }

        return $this->guardians->isAuthorised($signer, $student, GuardianPermission::Payments);
    }

    /**
     * Q-9's ١: every balance this student already holds opens its ceiling.
     *
     * Per balance rather than one number for the person: the ceiling is bounded
     * by the WORKSPACE's cadence and mode, so a student studying with a prepaid
     * teacher and a deferring one gets zero from the first and one from the
     * second — the same rule the floor applies, applied where the balance is.
     *
     * Through `SetCreditLimit`, so the audit line and the "you are no longer
     * blocked" message come from the one place they come from for every other
     * writer.
     */
    private function grantOpeningLimit(User $student): void
    {
        foreach ($this->accounts->balancesFor($student)->load('workspace') as $balance) {
            // Already carries a ceiling: leave it alone. Overwriting one would
            // make this a recompute, and the value it would overwrite is the one
            // FR-040's demotion wrote.
            if ($balance->credit_limit_credits !== 0) {
                continue;
            }

            $opening = $this->settings->initialLimitFor($balance->workspace);

            if ($opening > 0) {
                $this->limits->handle($balance, $opening, 'موافقة موثّقة على شروط الدفع المؤجَّل.');
            }
        }
    }
}
