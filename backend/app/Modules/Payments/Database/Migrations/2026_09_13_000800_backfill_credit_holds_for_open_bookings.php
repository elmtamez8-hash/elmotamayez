<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ٠٣٥ · T066 — الحجوزُ القائمةُ اليومَ تُجمِّدُ رصيدَها بأثرٍ رجعيّ.
 *
 * ⛔ AND THE PREDICATE IS NARROW ON PURPOSE. Measured on the development
 * database before this was written: **18** bookings stand at `booked`, and
 * **zero** of them are on a session that has not happened yet. The obvious
 * predicate — «every booked row» — would therefore write eighteen holds that
 * nothing will ever settle: no close, no cancel, no sweep reaches a session that
 * ended last month, so eighteen credits are subtracted from eighteen students'
 * available balance for the life of the platform, with the nightly invariant
 * GREEN (the rows really are unsettled and the counter really does match them).
 *
 * The three conditions are the whole of it: the seat is still `booked`, the
 * session has not reached a terminal status, and it has not frozen its billable
 * count — which is the same «still open» test `ClaimSubscriptionSeats` uses
 * before it seats anybody.
 *
 * ⛔ `uuid`, THE TIMESTAMPS AND `workspace_id` ARE PASSED EXPLICITLY. `DB::table`
 * boots no model, so `HasUuid` never fires — and MySQL downgrades the resulting
 * NOT NULL violation to a warning and stores `''`, after which every later hold
 * on the platform collides with that row on `unique(uuid)` and is silently
 * refused. The `insertOrIgnore` rule, reached from a migration.
 *
 * ⚠️ AND IT IS RE-RUNNABLE. `whereNotExists` means a second pass writes nothing,
 * and the counter is written ABSOLUTELY from a subquery rather than incremented,
 * so running this twice cannot double anybody's frozen total.
 */
return new class extends Migration
{
    public function up(): void
    {
        $written = [];

        DB::table('session_bookings')
            ->join('class_sessions', 'class_sessions.id', '=', 'session_bookings.class_session_id')
            ->join('credit_balances', function ($join): void {
                $join->on('credit_balances.course_id', '=', 'class_sessions.course_id')
                    ->on('credit_balances.student_user_id', '=', 'session_bookings.student_user_id');
            })
            ->where('session_bookings.status', 'booked')
            ->whereNotIn('class_sessions.status', ['completed', 'cancelled', 'interrupted'])
            ->whereNull('class_sessions.billable_seats')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('credit_holds')
                    ->whereColumn('credit_holds.credit_balance_id', 'credit_balances.id')
                    ->whereColumn('credit_holds.class_session_id', 'class_sessions.id');
            })
            ->select([
                'session_bookings.id as booking_id',
                'credit_balances.id as balance_id',
                'class_sessions.id as session_id',
                'class_sessions.workspace_id as workspace_id',
                'session_bookings.student_user_id as student_user_id',
            ])
            // By id, never by OFFSET: the predicate shrinks under the walk as
            // rows are written, so `chunk()` skips as many rows as each page
            // fixed — and reports success.
            ->orderBy('session_bookings.id')
            ->chunkById(200, function ($rows) use (&$written): void {
                $now = now();
                $insert = [];

                foreach ($rows as $row) {
                    $insert[] = [
                        'uuid' => (string) Str::uuid(),
                        'credit_balance_id' => $row->balance_id,
                        'class_session_id' => $row->session_id,
                        'student_user_id' => $row->student_user_id,
                        'workspace_id' => $row->workspace_id,
                        'credits' => 1,
                        'held_at' => $now,
                        'hold_seq' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $written[(int) $row->balance_id] = true;
                }

                if ($insert !== []) {
                    DB::table('credit_holds')->insertOrIgnore($insert);
                }
            }, 'session_bookings.id', 'booking_id');

        if ($written === []) {
            return;
        }

        // Absolute, from the rows themselves — the same statement
        // `SettleCreditHold` writes, for the same reason.
        DB::table('credit_balances')
            ->whereIn('id', array_keys($written))
            ->update([
                'held_credits' => DB::raw(
                    '(SELECT COALESCE(SUM(h.credits), 0) FROM credit_holds h'
                    .' WHERE h.credit_balance_id = credit_balances.id AND h.settled_at IS NULL)'
                ),
                'updated_at' => now(),
            ]);
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would have to tell a hold this migration wrote from one a
     * student's own booking placed a minute later, and nothing on the row says
     * which. Leaving them is harmless: every one of them belongs to a seat that
     * is still open, so the ordinary settlement doors will judge it.
     */
    public function down(): void {}
};
