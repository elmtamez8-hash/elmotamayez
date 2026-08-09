<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Events\AccessRestored;
use App\Modules\Payments\Events\AccessWithheld;
use App\Modules\Payments\Events\BalanceThresholdCrossed;
use App\Modules\Payments\Events\BalanceUpdated;
use App\Modules\Payments\Models\CreditBalance;

/**
 * Everything a balance movement has to announce, in one place.
 *
 * Three Actions write to the ledger — a charge, a purchase, a manual adjustment
 * — and all three must announce the same four things. Left in each of them, the
 * third one written would be the one missing the withholding flip, and a student
 * would stay silently blocked after a correction that unblocked them.
 *
 * ⚠️ THE BEFORE-STATE MUST BE TAKEN BEFORE THE WRITE. {@see self::isBlocked()}
 * is called first, its answer held, and passed back in afterwards. A "was it
 * blocked" computed after the movement is the state it is in NOW, which makes
 * every transition invisible — the class would run, dispatch nothing, and look
 * like it worked.
 *
 * ⚠️ AND `crossed_tier` IS READ BEFORE `refresh()`. It is a transient attribute
 * the ledger stamped on the instance inside the transaction (the shape
 * {@see WithholdingReader} uses for `is_withheld`), and a refresh replaces the
 * attribute bag — reading it afterwards yields null on every crossing.
 *
 * Withholding is derived, so nothing here restores or removes access; the
 * predicate is re-evaluated at the next booking and the next grant either way.
 * These events exist so the person can be TOLD, instead of finding out by being
 * refused (FR-033).
 */
class BalanceAnnouncer
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly ExamMode $examMode,
    ) {}

    /**
     * The withholding state of this balance as it stands.
     *
     * Called once before the movement and once after; the exam window is passed
     * in by the caller that charges a whole session, so thirty seats ask about it
     * once rather than sixty times.
     */
    public function isBlocked(CreditBalance $balance, ?bool $insideExamWindow = null): bool
    {
        $inExam = $insideExamWindow ?? $this->examMode->isOpen((int) $balance->workspace_id);

        return $this->ledger->isBlockedForBalance($balance, $this->ledger->floorForBalance($balance, $inExam));
    }

    public function announce(
        CreditBalance $balance,
        bool $wasBlocked,
        int $deltaCredits,
        ?bool $insideExamWindow = null,
    ): void {
        $crossed = $balance->getAttribute('crossed_tier');

        $balance->refresh();

        // The declared fourth link of the consumption chain, and the first thing
        // said about any movement whatever produced it.
        BalanceUpdated::dispatch($balance, $deltaCredits);

        if (is_int($crossed) && $crossed > 0) {
            BalanceThresholdCrossed::dispatch($balance, $crossed);
        }

        $isBlocked = $this->isBlocked($balance, $insideExamWindow);

        if ($isBlocked && ! $wasBlocked) {
            AccessWithheld::dispatch($balance);
        }

        if (! $isBlocked && $wasBlocked) {
            AccessRestored::dispatch($balance);
        }
    }

    /**
     * The same flip, announced when NO credit moved.
     *
     * The other two writers of the withheld state are not movements at all — the
     * credit limit (US6) and the exam-mode window (US8) change the FLOOR, and the
     * balance beneath it does not move a credit. Sending them through
     * {@see self::announce()} would announce a `BalanceUpdated` with a delta of
     * zero and re-rank a threshold nothing crossed, so a student would be told
     * their balance changed on a day nobody touched it.
     *
     * ⚠️ WITHOUT THIS, A STUDENT LEARNS THEY ARE BLOCKED BY BEING REFUSED.
     * Detecting the change inside the movement alone misses every flip that a
     * ceiling caused, and the person finds out at their next booking (FR-033).
     */
    public function announceStandingChange(CreditBalance $balance, bool $wasBlocked): void
    {
        $balance->refresh();

        $isBlocked = $this->isBlocked($balance);

        if ($isBlocked && ! $wasBlocked) {
            AccessWithheld::dispatch($balance);
        }

        if (! $isBlocked && $wasBlocked) {
            AccessRestored::dispatch($balance);
        }
    }
}
