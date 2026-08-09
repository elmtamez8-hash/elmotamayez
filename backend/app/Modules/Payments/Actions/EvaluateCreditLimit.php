<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\DeferralConsent;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * The ceiling moves by rule, and the rule belongs to the system (FR-037 · Q-9).
 *
 * Three questions, in the order they are asked:
 *
 *   1. A mode that defers nothing has no ceiling at all. FR-014 forbids going
 *      below zero in `PREPAID_CREDITS`, and a limit standing at 3 in a workspace
 *      whose floor ignores it is a number in the panel that means nothing.
 *   2. Fourteen days below zero and the ceiling goes to zero (FR-040). That IS
 *      the switch to prepaid for that student — the floor is `−limit`, so a
 *      ceiling of zero and a prepaid mode produce the identical predicate. A
 *      per-student `mode` column would be a second answer to a question the floor
 *      already answers, and the two would disagree the first time either moved.
 *   3. Every N on-time payments earn +1, up to the platform's cap.
 *
 * ⚠️ IT IS NOT A RECOMPUTE, and that is what makes the demotion stick. A
 * `limit = f(consent, history)` recomputed from scratch would hand the defaulted
 * student their ceiling back on their first purchase after it, and Q-9's "ينخفض
 * إلى صفر" would have lasted exactly one payment. The limit moves by DELTAS, and
 * the counter that earns them is CONSUMED when it pays out — so running this
 * twice over the same balance moves nothing the second time. That idempotence is
 * what lets a nightly sweep and a purchase both call it without coordination.
 *
 * ⚠️ THE INITIAL 0 → 1 GRANT IS NOT HERE. FR-048 makes recorded consent the gate,
 * and recording that consent is US9's Action, which does not exist yet. Granting
 * a ceiling here from the mere absence of a refusal would be the opposite of what
 * the requirement says. Until then a ceiling starts at zero and moves by the
 * platform's own hand (`PATCH .../limit`), which is where FR-048's check can be
 * enforced today.
 */
class EvaluateCreditLimit extends Action
{
    public function __construct(
        private readonly BillingSettings $settings,
        private readonly DeferralConsent $consent,
        private readonly SetCreditLimit $writer,
    ) {}

    /** @return int the ceiling as it stands after the evaluation */
    public function handle(CreditBalance $balance): int
    {
        if (! $this->settings->mode($balance->workspace)->allowsDeferral()) {
            return $this->writer->handle($balance, 0, 'النمط المعتمد لا يسمح بالتأجيل.');
        }

        if ($this->isOverdue($balance)) {
            $days = $this->settings->decreaseAfterLateDays();

            // The counter goes with it. A student who paid twice on time and then
            // defaulted must not be two thirds of the way to a raise the moment
            // they clear the debt.
            $this->setStreak($balance, 0);

            return $this->writer->handle(
                $balance,
                0,
                "رصيد سالب منذ أكثر من {$days} يوماً — تحويل إلى الدفع المسبق.",
            );
        }

        $per = $this->settings->increaseAfterOnTime();
        $earnedSteps = intdiv($balance->on_time_payments, $per);

        if ($earnedSteps < 1) {
            return $balance->credit_limit_credits;
        }

        // Asked BEFORE the counter is spent. {@see SetCreditLimit} would refuse
        // the raise anyway, but it refuses by throwing — and this call sits in the
        // after-commit block of an approved purchase, where an exception is a
        // 500 on a payment that has already been taken.
        if (! $this->consent->recordedFor($balance->student()->firstOrFail())) {
            return $balance->credit_limit_credits;
        }

        // Consumed, never merely compared: the remainder carries forward toward
        // the next raise, and what has been paid out cannot be paid out again.
        $this->setStreak($balance, $balance->on_time_payments % $per);

        return $this->writer->handle(
            $balance,
            min(
                $balance->credit_limit_credits + ($earnedSteps * $this->settings->increaseByCredits()),
                $this->settings->maxLimitCredits(),
            ),
            'سداد في المواعيد.',
        );
    }

    /**
     * Whether the balance has been under water longer than the platform allows.
     *
     * `negative_since` is the moment it FIRST went under, and the ledger clears it
     * the moment the balance comes back up — so this is one comparison rather than
     * a walk through the entries.
     */
    private function isOverdue(CreditBalance $balance): bool
    {
        $since = $balance->negative_since;

        return $since !== null && $since->lte(now()->subDays($this->settings->decreaseAfterLateDays()));
    }

    private function setStreak(CreditBalance $balance, int $value): void
    {
        DB::table('credit_balances')->where('id', $balance->getKey())->update(['on_time_payments' => $value]);

        $balance->setAttribute('on_time_payments', $value);
    }
}
