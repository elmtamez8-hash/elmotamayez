<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Events\AccessRestored;
use App\Modules\Payments\Events\AccessWithheld;
use App\Modules\Payments\Events\BalanceThresholdCrossed;
use App\Modules\Payments\Events\BalanceUpdated;
use App\Modules\Payments\Models\CreditBalance;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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
        private readonly WithholdingReader $withholding,
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

    /**
     * The part of an announcement that is about the MOVEMENT alone.
     *
     * Split out because the withholding flip is the only half whose inputs are
     * shared by a whole room — the billing mode, the zero-balance behaviour, an
     * open exam window and a terms consent are facts about a workspace and a
     * moment, and thirty students in one class share every one of them. A caller
     * moving many balances at once therefore uses this per balance and
     * {@see self::announceStandingChanges()} once, instead of paying for those
     * four facts thirty times over.
     *
     * A single-balance caller wants both halves and should keep calling
     * {@see self::announce()}, which is this plus the flip.
     */
    public function announceMovement(CreditBalance $balance, int $deltaCredits): void
    {
        $crossed = $balance->getAttribute('crossed_tier');

        /*
        | ⚠️ ATTRIBUTES ONLY — `refresh()` WOULD RELOAD THE RELATIONS TOO.
        |
        | What is needed here is the counters after the atomic UPDATE, nothing
        | more. `refresh()` also re-`load()`s every relation already on the model,
        | so a balance carrying its workspace — which it does, because the ledger
        | reads the alert thresholds off it — pays a second `workspaces` SELECT
        | per movement. In a thirty-seat class that is thirty needless reads of
        | one row, on top of the thirty the loading itself caused.
        */
        $fresh = $balance->newQueryWithoutScopes()->whereKey($balance->getKey())->first();

        if ($fresh !== null) {
            $balance->setRawAttributes($fresh->getAttributes(), true);
        }

        // The declared fourth link of the consumption chain, and the first thing
        // said about any movement whatever produced it.
        BalanceUpdated::dispatch($balance, $deltaCredits);

        if (is_int($crossed) && $crossed > 0) {
            BalanceThresholdCrossed::dispatch($balance, $crossed);
        }
    }

    public function announce(
        CreditBalance $balance,
        bool $wasBlocked,
        int $deltaCredits,
        ?bool $insideExamWindow = null,
    ): void {
        $this->announceMovement($balance, $deltaCredits);

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

    /**
     * The same question for a whole list, in a fixed number of queries.
     *
     * One writer moves many people at once: an exam-mode window forces the floor
     * to zero for every student in the workspace (US8). Announcing that through
     * {@see self::isBlocked()} would resolve the mode and the window once per
     * student — two queries each, on a workspace-sized list.
     *
     * @param  EloquentCollection<int, CreditBalance>  $balances
     * @return array<int, bool> keyed by balance id
     */
    public function standingsFor(EloquentCollection $balances): array
    {
        $stamped = $this->withholding->stamp($balances);

        $standings = [];

        foreach ($stamped as $balance) {
            $standings[(int) $balance->getKey()] = (bool) $balance->getAttribute('is_withheld');
        }

        return $standings;
    }

    /**
     * Announce every flip in a list against the standings taken before the write.
     *
     * ⚠️ THE BALANCES MUST BE RE-READ, not the same instances measured before:
     * `stamp()` writes `is_withheld` onto the model it is given, so re-stamping
     * the earlier collection would overwrite the very answers being compared
     * against and every transition would read as "no change".
     *
     * @param  EloquentCollection<int, CreditBalance>  $balances
     * @param  array<int, bool>  $wasBlocked  keyed by balance id
     */
    public function announceStandingChanges(EloquentCollection $balances, array $wasBlocked): void
    {
        foreach ($this->withholding->stamp($balances) as $balance) {
            $id = (int) $balance->getKey();
            $isBlocked = (bool) $balance->getAttribute('is_withheld');
            // Absent means the row did not exist when the before-state was taken
            // — a balance created by a purchase between the two reads. `false` is
            // the right default rather than a convenient one: a balance that does
            // not exist is not withheld, the same rule the whole predicate rests
            // on, so a newcomer who lands blocked is correctly announced as a
            // fresh withholding.
            $before = $wasBlocked[$id] ?? false;

            if ($isBlocked && ! $before) {
                AccessWithheld::dispatch($balance);
            }

            if (! $isBlocked && $before) {
                AccessRestored::dispatch($balance);
            }
        }
    }
}
