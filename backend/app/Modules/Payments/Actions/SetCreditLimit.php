<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\BalanceAnnouncer;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;

/**
 * The one place `credit_limit_credits` is CHANGED.
 *
 * Three writers arrive here — the rules ({@see EvaluateCreditLimit}), the
 * platform's exception (`Manage\CreditLimitController`) and the initial grant
 * that a recorded consent earns ({@see RecordTermsConsent}) — because everything
 * that has to happen around the write is the same for all three: the cap, the
 * consent gate, the audit line and telling whoever it just blocked or freed. Left
 * in each caller, the last one written would be the one missing the audit line,
 * and FR-039 would hold only for the path someone remembered.
 *
 * ⚠️ "CHANGED", NOT "WRITTEN", AND THE DISTINCTION IS ONE DELIBERATE EXCEPTION.
 * `CreditAccounts::balanceFor()` gives a NEW balance its opening ceiling as a
 * column default, which is not a change: there is no `from` to audit, nobody to
 * announce a transition to, and the row does not exist yet to be blocked. The gate
 * and the cap still apply there — they are inside
 * `BillingSettings::initialLimitFor()`, which is what that path writes.
 *
 * ⚠️ THE CAP AND THE GATE ARE ENFORCED HERE, NOT IN A FormRequest (R16). The
 * sweep, the purchase path and the panel all reach this Action, and a rule that
 * exists only on the HTTP path is a rule with a door beside it.
 *
 * The reason is mandatory for the same reason it is mandatory on
 * {@see AdjustCredits}: FR-039 asks for it, and a nullable column is one caller
 * away from an audit trail of blanks.
 */
class SetCreditLimit extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly BillingSettings $settings,
        private readonly BalanceAnnouncer $announcer,
        private readonly ConsentRegistry $consent,
    ) {}

    /** @return int the ceiling as it stands after the write */
    public function handle(CreditBalance $balance, int $limit, string $reason, ?User $performedBy = null): int
    {
        if (trim($reason) === '') {
            throw new DomainException('السبب إلزامي لكل تغيير في الحد الائتماني.');
        }

        if ($limit < 0) {
            throw new DomainException('الحد الائتماني لا يكون سالباً.');
        }

        if ($limit > $this->settings->maxLimitCredits()) {
            throw new DomainException(
                'الحد المطلوب يتجاوز السقف الأقصى المسموح به على المنصة ('.$this->settings->maxLimitCredits().').',
            );
        }

        // FR-048 — no agreement, no debt. Refused rather than silently clamped to
        // zero: a request that answers 200 with a ceiling the caller did not ask
        // for reads as a bug in the panel, and the person retries.
        if ($limit > 0 && ! $this->consent->has($balance->student()->firstOrFail(), ConsentDocument::DeferredPaymentTerms)) {
            throw new DomainException(
                'لا يمكن منح حد ائتماني قبل تسجيل موافقة صريحة على شروط الدفع المؤجَّل.',
            );
        }

        $from = $balance->credit_limit_credits;

        // Silent when nothing changes. A nightly sweep over a stable balance
        // would otherwise write one identical audit row per night, and the one
        // entry that mattered would be unfindable among them.
        if ($limit === $from) {
            return $from;
        }

        // Measured before the write, held, and passed back after it. A "was it
        // blocked" computed afterwards is the state it is in NOW, and every
        // transition becomes invisible ({@see BalanceAnnouncer}).
        $wasBlocked = $this->announcer->isBlocked($balance);

        $balance->forceFill(['credit_limit_credits' => $limit])->save();

        // activity_log, never a `credit_limit_changes` table (R15): the package
        // already stores the old value, the new one and the causer, and a third
        // copy of the same idea is a third thing to keep in step.
        //
        // The causer is passed explicitly as a property as well as through the
        // trait's Auth lookup: the sweep runs with no authenticated user, and an
        // audit row whose causer is null has to say so in a way that reads as a
        // decision rather than as a missing value.
        $this->logActivity('credit_limit.changed', $balance, [
            'from' => $from,
            'to' => $limit,
            'reason' => trim($reason),
            'performed_by' => $performedBy?->getKey(),
        ]);

        $this->announcer->announceStandingChange($balance, $wasBlocked);

        return $limit;
    }
}
