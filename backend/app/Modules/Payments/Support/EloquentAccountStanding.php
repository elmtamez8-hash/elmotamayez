<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Models\CreditBalance;
use App\Shared\Contracts\AccountStanding;
use Illuminate\Database\Eloquent\Collection;

/**
 * The withholding predicate, answered for callers outside Payments.
 *
 * Media refuses a high-value asset with it and LiveSessions refuses a booking
 * with it, and neither ever learns that `credit_balances` exists. Payments owns
 * the balance and binds this implementation; the callers hold the interface.
 *
 * ⚠️ BY COURSE, NEVER BY WORKSPACE. A student who owes for physics and has paid
 * for maths keeps the maths notes. Answering per workspace closes both, which is
 * not a rounding error — it is the wrong answer for a course that is paid up.
 *
 * ⚠️ A MISSING BALANCE ROW MEANS ZERO CREDITS, AND WHETHER ZERO IS ENOUGH IS THE
 * MODE'S QUESTION, NOT THIS CLASS'S. The account is created lazily — by the
 * first purchase or the first charge — so a newly enrolled student has no row at
 * all. Reading that absence as "nothing owing" in every mode is what left the
 * prepaid wall with a hole the exact shape of every student's first day: a
 * newcomer booked the whole timetable, the sessions were delivered, spec 014
 * earned the teacher their fee from the same event, and only then was the row
 * created — at −1, −2, −8. Thirty newcomers in one group class is 240 seats
 * taught and nothing collected.
 *
 * So the absence is answered by asking the workspace how it collects:
 *
 *   · deferral allowed (manual collection, gateway, hybrid) — NOT withheld. This
 *     is the case the old comment was right about: a workspace that takes cash
 *     and never sells a credit must not lock its students out of a table it
 *     never writes to.
 *   · deferral forbidden (prepaid, the launch default) — WITHHELD. Zero credits
 *     cannot pay for a session, and that is the whole of what prepaid means.
 *
 * ⚠️ AND IT IS A READ. The obvious alternative — materialise the row through
 * CreditAccounts and let the ordinary predicate answer — would have a booking
 * ATTEMPT write to `credit_balances`, so every refused newcomer would leave a
 * row behind and the reconciliation job would count them for ever.
 */
class EloquentAccountStanding implements AccountStanding
{
    public function __construct(
        private readonly CreditAccounts $accounts,
        private readonly WithholdingReader $withholding,
        // No CreditLedger here any more, and its absence is the fix: this class
        // used to hold one so it could compute a floor of its own beside the
        // reader's. One floor per balance, computed where its inputs are known.
        private readonly BillingSettings $settings,
        private readonly SubscriptionEligibility $subscriptions,
    ) {}

    /**
     * ⚠️ ONE READ OF THE BALANCES, AND IT USED TO BE TWO — ON THE HOTTEST PATH IN
     * THE PRODUCT.
     *
     * `withheldCourseIdsFor()` reads them and stamps them; when the answer came
     * back "not withheld" the fall-through asked `prepaidWithNoBalance()`, which
     * read the very same balances again. Six queries where four will do, and this
     * is asked by `IssueJoinTicket` on EVERY presence heartbeat — thirty students
     * twice a minute, per room.
     *
     * It also stamps ONE balance rather than the whole set: a student with eight
     * teachers was paying for eight teachers' workspaces and exam windows to
     * answer a question about one course. The bulk form is still there and still
     * correct — it is what the panel wants, and it is not what this wants.
     */
    public function isWithheld(User $student, int $courseId): bool
    {
        /*
        | ⚠️ A LIVE SUBSCRIPTION LIFTS WITHHOLDING FOR WHAT IT COVERS (011 ·
        | FR-026 · FR-028), AND THIS IS THE ONE SEAM THAT REACHES EVERY DOOR.
        | `BookingEligibility` asks this at booking and again at the room, and
        | Media asks it before it will play a high-value asset — three doors, one
        | question. A subscriber's credit balance is legitimately zero and stays
        | zero (their seats cost nothing), so without this line the very first
        | booking of a paid month is refused with «رصيدك لا يكفي» and the money
        | they just handed over buys nothing at all.
        |
        | Asked FIRST, before the balance is read: for a subscriber it is also the
        | cheaper question, and this is the hottest path in the product.
        */
        if ($this->subscriptions->coversCourse((int) $student->getKey(), $courseId)) {
            return false;
        }

        $own = $this->accounts->balancesFor($student)->firstWhere('course_id', $courseId);

        if ($own === null) {
            return $this->prepaidWithNoBalance($courseId);
        }

        return (bool) $this->withholding->stamp(new Collection([$own]))
            ->first()
            ?->getAttribute('is_withheld');
    }

    /**
     * @return list<int>
     */
    public function withheldCourseIdsFor(User $student): array
    {
        $balances = $this->accounts->balancesFor($student);

        if ($balances->isEmpty()) {
            return [];
        }

        // One read of the mode and the exam window for every workspace in the
        // list, not one per row. The bulk form is the whole reason this method
        // exists: a per-course call inside a loop is the N+1 the contract
        // forbids, and NFR-012 gives the panel a fixed query budget.
        //
        // ⚠️ AND THE SUBSCRIPTION LIFT IS BULK TOO. The single-course form above
        // reads one student's live subscriptions; doing that per row here would
        // be the same N+1 this method was written to avoid, so the covered set is
        // resolved once for the whole list.
        $covered = array_flip($this->subscriptions->coveredCourseIds(
            (int) $student->getKey(),
            array_values($balances->map(fn (CreditBalance $balance): int => (int) $balance->course_id)->all()),
        ));

        $withheld = $this->withholding->stamp($balances)
            ->reject(fn (CreditBalance $balance): bool => isset($covered[(int) $balance->course_id]))
            ->filter(fn (CreditBalance $balance): bool => (bool) $balance->getAttribute('is_withheld'))
            ->map(fn (CreditBalance $balance): int => (int) $balance->course_id);

        // array_values on the plain array, not Collection::values(): a Collection
        // keeps its keys through map/filter, and `values()` on it still hands
        // back an array whose list-ness the analyser cannot see.
        return array_values($withheld->all());
    }

    /**
     * Whether the absence of a row is itself a refusal, in this workspace.
     *
     * The course is fetched without the workspace scope on purpose: this is
     * asked from LiveSessions and Media while the reader's current workspace may
     * be another teacher's entirely, and the question is about the COURSE's
     * workspace. A course that has been deleted, or one attached to no workspace
     * (the pre-Q-7 rows the backfill left alone), answers "not withheld" — there
     * is no mode to ask, and inventing one would refuse a booking over a fact
     * nobody recorded.
     */
    private function prepaidWithNoBalance(int $courseId): bool
    {
        // The caller has already established there is no balance row, so the
        // student is no longer a parameter: re-reading the balances to prove it
        // again was half the cost of every heartbeat.
        $workspace = Course::query()->withoutWorkspaceScope()->find($courseId)?->workspace;

        return $workspace !== null && ! $this->settings->mode($workspace)->allowsDeferral();
    }

    public function creditsNeededFor(User $student, int $courseId): int
    {
        $balance = $this->accounts->balancesFor($student)->firstWhere('course_id', $courseId);

        if ($balance === null) {
            // One session's worth, when the missing row is itself the refusal.
            // Zero here would print "تحتاج 0 حصة على الأقل" on the one screen a
            // newcomer sees first — a refusal that asks for nothing.
            return $this->prepaidWithNoBalance($courseId) ? 1 : 0;
        }

        // READ off the stamp, never recomputed beside it. The reader knows the
        // exam window and the consent; a second computation here knew neither,
        // and quoted a number the booking gate did not agree with.
        return (int) ($this->withholding->stamp(new Collection([$balance]))
            ->first()?->getAttribute('credits_needed') ?? 0);
    }
}
