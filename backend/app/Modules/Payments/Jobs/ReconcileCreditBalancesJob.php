<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditReconciliationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Does the ledger still add up? Asked nightly, in three ways.
 *
 * ⚠️ A JOB, NEVER A GET. Every check here is a `GROUP BY` across the fastest
 * growing tables of this phase with no tenant filter and no pagination. On a
 * request it would run whenever an admin opened the page, twice if they
 * refreshed, on production. It runs once a night and writes what it found — and
 * every check is written in the grouped form for the same reason, because a
 * per-row count inside a walk over every balance is the shape it was avoiding.
 *
 * ⚠️ AND THE FIRST CHECK ALONE IS BLIND, WHICH IS WHY THERE ARE THREE. The
 * balance and its entries are written by the same path inside the same
 * transaction, so a session that was never charged AT ALL leaves them in perfect
 * agreement — the entry is missing and the balance was never moved, and 1 = 1.
 * The two invariants that see it come from OUTSIDE the ledger:
 *
 *   · a charged session must carry one consumption entry per seat it was taught
 *     to (this is the one that catches a half-charged session, where three of
 *     five students were debited before the worker died);
 *   · a positive balance must equal the credits left in its lots, which is the
 *     drawer's own arithmetic checked against the counter it maintains.
 *
 * Nothing here repairs anything. A sweep that silently corrected a balance would
 * destroy the evidence of what went wrong, and the append-only ledger has no
 * shape for an undo — a correction is `AdjustCredits`, typed by a person, with a
 * reason.
 */
class ReconcileCreditBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How many findings are stored in full.
     *
     * `findings_count` is always the true total, and a truncated run says so in
     * the log: a cap that reports itself as "everything" is how a broken deploy
     * reads as three problems instead of nine thousand.
     */
    private const SAMPLE_LIMIT = 200;

    public function handle(): void
    {
        $findings = [
            ...$this->ledgerAgainstBalances(),
            ...$this->lotsAgainstBalances(),
            ...$this->sessionsAgainstEntries(),
        ];

        if (count($findings) > self::SAMPLE_LIMIT) {
            Log::warning('credit reconciliation truncated its stored sample', [
                'found' => count($findings),
                'stored' => self::SAMPLE_LIMIT,
            ]);
        }

        CreditReconciliationRun::query()->create([
            'ran_at' => now(),
            'balances_checked' => DB::table('credit_balances')->count(),
            'sessions_checked' => DB::table('class_sessions')->whereNotNull('charged_at')->count(),
            'findings_count' => count($findings),
            'findings' => array_slice($findings, 0, self::SAMPLE_LIMIT),
        ]);
    }

    /**
     * The obvious one: the sum of a balance's entries is its remaining credits.
     *
     * Kept even though it is the weakest of the three — it is the only one that
     * catches a balance written outside the ledger, which is the failure the
     * whole "one writer" rule exists to prevent and therefore the one nobody
     * would be watching for.
     *
     * @return list<array<string, mixed>>
     */
    private function ledgerAgainstBalances(): array
    {
        $sums = DB::table('credit_transactions')
            ->selectRaw('credit_balance_id, SUM(credits) AS total')
            ->groupBy('credit_balance_id')
            ->pluck('total', 'credit_balance_id');

        $findings = [];

        DB::table('credit_balances')
            ->select(['id', 'workspace_id', 'student_user_id', 'remaining_credits'])
            ->orderBy('id')
            ->chunk(500, function (iterable $balances) use ($sums, &$findings): void {
                foreach ($balances as $balance) {
                    $expected = (int) ($sums[$balance->id] ?? 0);

                    if ($expected !== (int) $balance->remaining_credits) {
                        $findings[] = $this->finding(
                            'ledger_sum',
                            (int) $balance->workspace_id,
                            (int) $balance->id,
                            (int) $balance->student_user_id,
                            $expected,
                            (int) $balance->remaining_credits,
                        );
                    }
                }
            });

        return $findings;
    }

    /**
     * A positive balance equals what its lots still hold.
     *
     * Only when positive: below zero the balance is a debt, and there is no lot
     * behind a credit that was never bought — comparing there would report every
     * deferring student as broken, every night, and the real findings would be
     * unfindable among them.
     *
     * @return list<array<string, mixed>>
     */
    private function lotsAgainstBalances(): array
    {
        $lots = DB::table('credit_lots')
            ->selectRaw('credit_balance_id, SUM(credits_remaining) AS total')
            ->groupBy('credit_balance_id')
            ->pluck('total', 'credit_balance_id');

        $findings = [];

        DB::table('credit_balances')
            ->select(['id', 'workspace_id', 'student_user_id', 'remaining_credits'])
            ->where('remaining_credits', '>', 0)
            ->orderBy('id')
            ->chunk(500, function (iterable $balances) use ($lots, &$findings): void {
                foreach ($balances as $balance) {
                    $held = (int) ($lots[$balance->id] ?? 0);

                    if ($held !== (int) $balance->remaining_credits) {
                        $findings[] = $this->finding(
                            'lot_remainder',
                            (int) $balance->workspace_id,
                            (int) $balance->id,
                            (int) $balance->student_user_id,
                            $held,
                            (int) $balance->remaining_credits,
                        );
                    }
                }
            });

        return $findings;
    }

    /**
     * A charged session carries one consumption entry per seat it was taught to.
     *
     * The check that sees what the ledger cannot. Seat holders are the same
     * filter the register and the teaching unit use — booked, plus cancelled too
     * late to release the seat — so a disagreement here is a disagreement
     * between the student's charge and the teacher's pay.
     *
     * @return list<array<string, mixed>>
     */
    private function sessionsAgainstEntries(): array
    {
        // ⚠️ TWO GROUPED READS, NOT TWO QUERIES PER SESSION. The obvious shape —
        // walk the charged sessions and count each one's seats and entries — is
        // 2N queries over the table this job exists to avoid hammering, and it
        // grows with the platform. Grouped, it is the same answer in two.
        $seats = DB::table('session_bookings')
            ->selectRaw('class_session_id, COUNT(DISTINCT student_user_id) AS total')
            ->whereIn('status', [BookingStatus::Booked->value, BookingStatus::CancelledLate->value])
            ->groupBy('class_session_id')
            ->pluck('total', 'class_session_id');

        $entries = DB::table('credit_transactions')
            ->selectRaw('source_id, COUNT(*) AS total')
            ->where('type', CreditTransactionType::Consume->value)
            ->where('source_type', 'class_session')
            ->whereNotNull('source_id')
            ->groupBy('source_id')
            ->pluck('total', 'source_id');

        $findings = [];

        DB::table('class_sessions')
            ->select(['id', 'workspace_id'])
            ->whereNotNull('charged_at')
            ->orderBy('id')
            ->chunk(500, function (iterable $sessions) use ($seats, $entries, &$findings): void {
                foreach ($sessions as $session) {
                    $expected = (int) ($seats[$session->id] ?? 0);
                    $actual = (int) ($entries[$session->id] ?? 0);

                    if ($expected !== $actual) {
                        $findings[] = [
                            'check' => 'session_seats',
                            'workspace_id' => (int) $session->workspace_id,
                            'class_session_id' => (int) $session->id,
                            'expected' => $expected,
                            'actual' => $actual,
                        ];
                    }
                }
            });

        return $findings;
    }

    /**
     * One finding, from values already read off the row.
     *
     * The row is not passed in: `DB::table()` yields a bare `stdClass`, so a
     * parameter typed `object` gives the reader no idea what it may contain and
     * PHPStan no way to check it. The caller does the reading, where the select
     * list is three lines up.
     *
     * @return array<string, mixed>
     */
    private function finding(string $check, int $workspaceId, int $balanceId, int $studentId, int $expected, int $actual): array
    {
        return [
            'check' => $check,
            'workspace_id' => $workspaceId,
            'credit_balance_id' => $balanceId,
            'student_user_id' => $studentId,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }
}
