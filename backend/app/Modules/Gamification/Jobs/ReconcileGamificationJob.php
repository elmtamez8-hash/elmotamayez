<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Jobs;

use App\Modules\Gamification\Models\AwardEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly check that the aggregate still equals the sum of its entries (FR-005).
 *
 * ⚠️ BOUNDED BY MOVEMENT, NEVER A FULL SWEEP. "Compare every aggregate against
 * the sum of its ledger" is a `GROUP BY` over the fastest-growing table in the
 * product, every night. Only students whose ledger moved since the last run are
 * checked — which is also the only population where a drift could have appeared.
 *
 * ⚠️ AND A COMPARISON PROVES LESS THAN IT LOOKS. Both sides are written by the
 * same code path in the same transaction, so an award that never happened leaves
 * them in perfect agreement. This catches the drift that a partial failure would
 * cause; it cannot catch a missing award, which is why AwardListenerWiringTest
 * exists on the other side.
 */
class ReconcileGamificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK = 500;

    public function __construct(private readonly ?string $since = null)
    {
        $this->onQueue('maintenance');
    }

    /** @return list<int> the user ids that disagree */
    public function handle(): array
    {
        $since = $this->since ?? now()->subDay()->toDateTimeString();

        $movedUserIds = AwardEntry::query()
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('student_user_id');

        $drifted = [];

        foreach ($movedUserIds->chunk(self::CHUNK) as $chunk) {
            $ids = $chunk->all();

            $sums = AwardEntry::query()
                ->whereIn('student_user_id', $ids)
                ->groupBy('student_user_id')
                ->selectRaw('student_user_id, SUM(xp) as total')
                ->pluck('total', 'student_user_id');

            $stored = DB::table('student_progress')
                ->whereIn('user_id', $ids)
                ->pluck('xp', 'user_id');

            foreach ($sums as $userId => $total) {
                if ((int) ($stored[$userId] ?? 0) !== (int) $total) {
                    $drifted[] = (int) $userId;
                }
            }
        }

        if ($drifted !== []) {
            // Reported, never repaired automatically. A silent correction hides
            // the bug that caused the drift, and the ledger is the side to trust.
            Log::error('gamification.reconcile.drift', ['user_ids' => $drifted]);
        }

        return $drifted;
    }
}
