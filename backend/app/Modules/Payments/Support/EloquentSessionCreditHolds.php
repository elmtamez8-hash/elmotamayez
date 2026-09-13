<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\PlaceCreditHold;
use App\Modules\Payments\Actions\SettleCreditHold;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditHold;
use App\Shared\Contracts\SessionCreditHolds;
use App\Shared\Data\CreditHoldResult;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * ٠٣٥ — الجانبُ الذي يعرفُ الفوترة، من العقدِ الذي لا يعرفُها.
 *
 * Bound `scoped()` in this module's provider, for the two OPPOSITE reasons
 * written above the binding beside it: not `bind()`, because the hold is asked
 * once per booking and read once per session card; and not `singleton()`,
 * because a worker's container outlives the job and this class reads billing
 * settings that an operator changes while the worker is running.
 *
 * @see SessionCreditHolds for why this is a contract and not a query
 */
class EloquentSessionCreditHolds implements SessionCreditHolds
{
    public function __construct(
        private readonly CreditAccounts $accounts,
        private readonly PlaceCreditHold $place,
        private readonly SettleCreditHold $settle,
    ) {}

    public function place(User $student, int $classSessionId, int $courseId, int $workspaceId): CreditHoldResult
    {
        $course = Course::query()->withoutWorkspaceScope()->find($courseId);

        if ($course === null) {
            // No course, no balance to freeze against. Granted rather than
            // refused: a session with no course charges nobody either, so
            // refusing here would close the seat over an accounting absence.
            return new CreditHoldResult(granted: true);
        }

        $balance = $this->accounts->balanceFor($student, $course);

        if ($this->place->handle($balance, $classSessionId)) {
            return new CreditHoldResult(
                granted: true,
                availableCredits: $this->available($balance->refresh()),
            );
        }

        $held = $this->heldFor($student, $courseId);

        return CreditHoldResult::refused(
            availableCredits: $this->available($balance->refresh()),
            firstReleaseAt: $held['first_release_at'],
        );
    }

    public function release(int $classSessionId, array $studentUserIds = []): int
    {
        return $this->settle->handle($classSessionId, CreditHold::OUTCOME_RELEASED, $studentUserIds);
    }

    /**
     * ⚠️ `withoutWorkspaceScope()` WITH THE OWNERSHIP PREDICATE WRITTEN OUT, and
     * the scope is no guard here in either direction: for a student who
     * registered themselves it is inert (their context is null), and for one who
     * carries a `last_workspace_id` — and `workspace_members` really does hold
     * student rows — it bites the WRONG way and hides a hold placed with another
     * teacher.
     *
     * @return array{held: int, available: int, first_release_at: string|null}
     */
    public function heldFor(User $student, int $courseId): array
    {
        $rows = CreditHold::query()
            ->withoutWorkspaceScope()
            ->join('credit_balances', 'credit_balances.id', '=', 'credit_holds.credit_balance_id')
            ->join('class_sessions', 'class_sessions.id', '=', 'credit_holds.class_session_id')
            ->where('credit_holds.student_user_id', $student->getKey())
            ->where('credit_balances.course_id', $courseId)
            ->whereNull('credit_holds.settled_at')
            ->selectRaw('SUM(credit_holds.credits) AS held, MIN(class_sessions.ends_at) AS soonest')
            ->first();

        $soonest = $rows?->getAttribute('soonest');

        /*
        | ⛔ `DB::table`, NEVER `balanceFor()` — A READ MUST NOT WRITE. That
        | accessor is a `firstOrCreate`, so asking it here would mint a
        | `credit_balances` row for every student who merely LOOKED at a booking
        | refusal and never had a balance at all.
        |
        | ⚠️ AND IT IS ONE QUERY BECAUSE OF WHERE THIS IS CALLED FROM.
        | `BookingEligibility::openingRefusal()` runs inside the presence
        | heartbeat, whose budget is 15 against a steady state measured at 14 and
        | whose docblock says the ceiling is not raised. `unlockOfferFor()` reads
        | the same two columns the same way, one file over.
        |
        | Read from the balance rather than subtracted from the sum above:
        | `held_credits` is the counter every claim moves, and a total recomputed
        | from the hold rows would be a second answer that disagrees with the
        | floor the booking is actually judged against.
        */
        $balance = DB::table('credit_balances')
            ->where('course_id', $courseId)
            ->where('student_user_id', $student->getKey())
            ->first(['remaining_credits', 'held_credits']);

        return [
            'held' => (int) ($rows?->getAttribute('held') ?? 0),
            'available' => (int) ($balance->remaining_credits ?? 0)
                - (int) ($balance->held_credits ?? 0),
            // ⚠️ THE SENTENCE, NOT DECORATION. «لا رصيد» with no date is a
            // refusal the student can do nothing with; the session's END is when
            // the hold is judged, so it is the honest answer to «when do I get
            // it back».
            'first_release_at' => $soonest instanceof DateTimeInterface
                ? $soonest->format(DateTimeInterface::ATOM)
                : (is_string($soonest) && $soonest !== ''
                    ? (date_create_immutable($soonest) ?: null)?->format(DateTimeInterface::ATOM)
                    : null),
        ];
    }

    /**
     * Owned minus frozen — computed, never a column.
     *
     * A third counter would be a second answer to a question the two existing
     * ones already answer, and it drifts from `remaining - held` at the first
     * write that forgets it.
     */
    private function available(CreditBalance $balance): int
    {
        return $balance->remaining_credits - $balance->held_credits;
    }
}
