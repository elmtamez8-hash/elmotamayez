<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The teacher showed up, stayed, and the session ran to its end (FR-056).
 *
 * This is the event billing (006) and payout (014) hang off: no credit is
 * consumed and no earning is generated without it (FR-058). It is fired from
 * exactly one place — CloseClassSession — so the rule is read in one file
 * instead of chased across controllers.
 *
 * `billableSeats` travels with it because it is a fact about a past moment: what
 * was booked when the cancellation window shut. Consumers must not recompute it
 * from live bookings (FR-060).
 *
 * ⚠️ `subscriptionSeats` IS A LIST OF STUDENT IDS, NOT A COUNT (027 · FR-048).
 * Settlement writes one teaching unit PER SEAT HOLDER, so a bare number cannot
 * say WHICH of those rows carries the subscriber price — and the difference
 * between 20 subscribers paid per lesson and 20 subscribers paid per month is
 * the whole requirement. A list of integers names no table and no class from the
 * money module, so FR-048أ's wall holds exactly as it did: the event stays the
 * one bridge, and it carries a fact rather than a lookup.
 *
 * ⚠️ `chargedSeats` IS A NUMBER AND NOT A MODEL, and the precedent is in this
 * same file: `billableSeats` and `subscriptionSeats` are both bare values for
 * exactly this reason. It is what the TEACHER IS PAID ON (٠٣٥ · FR-014) — the
 * attender, the silent no-show and the late canceller — while
 * `class_sessions.attended_seats` beside it is for display alone.
 *
 * ⛔ AND IT IS READ BACK FROM THE ROW AFTER THE CONDITIONAL UPDATE HAS WON, never
 * off `$session`. This event carries `Dispatchable` and NOT `SerializesModels`,
 * so a queued listener unserializes the model's attributes exactly as they stood
 * at dispatch — and a conditional UPDATE does not refresh the in-memory model.
 * Read from `$session` and every queued consumer sees null for ever, falls back
 * to the pre-035 rule on EVERY session, while the periodic sweep re-fetches and
 * answers correctly: two answers to one question, and the wrong one is the
 * default.
 */
class SessionDelivered
{
    use Dispatchable;

    /**
     * @param  list<int>  $subscriptionSeats  seat holders whose subscription covers this session
     * @param  int|null  $chargedSeats  the frozen verdict read back from the row.
     *                                  NULL means «not computed» — a session
     *                                  delivered before ٠٣٥ landed, or the deploy
     *                                  window in which the code is up and the
     *                                  migration is not. Consumers fall back to
     *                                  `billableSeats`, which is the rule that was
     *                                  actually in force when it was delivered.
     */
    public function __construct(
        public readonly ClassSession $session,
        public readonly int $billableSeats,
        public readonly array $subscriptionSeats,
        public readonly ?int $chargedSeats = null,
    ) {}
}
