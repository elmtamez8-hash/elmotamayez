<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SubscriptionDirectory;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * The seats a subscription pays for, taken without the student pressing anything
 * (027 · FR-039 … FR-045أ).
 *
 * ⚠️ THE PER-SEAT DECISION IS WRITTEN ONCE, IN `claimOne()`, AND TWO CALLERS
 * ENUMERATE INTO IT. This Action walks one student across many sessions (the
 * moment a subscription is activated); `BookSubscribersOnScheduled` walks one
 * session across many students (every time a session is scheduled or assigned to
 * a group). They are the same question asked from two directions, and two
 * spellings of the status table below is the defect family this repository has
 * paid for more than any other.
 *
 * ⚠️ AND NOTHING IS ROLLED BACK. A seat that could not be taken is REPORTED, not
 * an error: refunding a paid subscription because the seventh session filled up
 * is cancelling a month over one hour (FR-042).
 *
 * ⚠️ THE NAME PREDATES 052, WHICH ADDED THE CREDIT-PAYING MEMBER. `claimOne()`
 * is the subscriber's door and `claimOneAsMember()` the member's; both share one
 * status table in `decide()`, because two spellings of it is the defect family
 * named above. The class is not renamed for the same reason
 * `media.bunny.source_disk` was not.
 */
class ClaimSubscriptionSeats extends Action
{
    public function __construct(
        private readonly BookSeat $seats,
        private readonly SubscriptionDirectory $subscriptions,
    ) {}

    /**
     * @return array{booked: int, refused: list<array{uuid: string, title: string, starts_at: string, reason: string}>}
     */
    public function handle(
        int $workspaceId,
        User $student,
        int $courseId,
        int $cohortId,
        DateTimeInterface $windowEnd,
    ): array {
        $sessions = $this->futureSessionsOf($workspaceId, $courseId, $cohortId, $windowEnd);

        if ($sessions->isEmpty()) {
            return ['booked' => 0, 'refused' => []];
        }

        $existing = $this->existingBookings($sessions->modelKeys(), $student);

        $booked = 0;
        $refused = [];

        foreach ($sessions as $session) {
            $refusal = $this->claimOne($session, $student, $existing->get($session->getKey()));

            if ($refusal === null) {
                $booked++;

                continue;
            }

            if ($refusal === '') {
                // Nothing to do and nothing to report: already seated, or a seat
                // the student cancelled themselves.
                continue;
            }

            $refused[] = [
                'uuid' => (string) $session->uuid,
                'title' => (string) $session->title,
                'starts_at' => $session->starts_at->toIso8601String(),
                'reason' => $refusal,
            ];
        }

        return ['booked' => $booked, 'refused' => $refused];
    }

    /**
     * One student, one session — the whole decision.
     *
     * @param  SessionBooking|null  $existing  the student's row for this session, if they have one
     * @return string|null null when a seat was taken, `''` when there was
     *                     deliberately nothing to do, otherwise the reason —
     *                     which the caller reports to the student and the teacher
     *
     * ⚠️ THE EXISTING ROW IS SORTED BY STATUS, NEVER SKIPPED WHOLESALE. «Skip any
     * status» makes FR-044 free and makes FR-045 kill renewal: a subscriber who
     * lapses has their seats RELEASED, renews, and those sessions are skipped for
     * ever — paid for, unbooked, and with no error message anywhere. The same
     * for a transfer A → B → A.
     *
     * ⚠️ AND A RELEASED ROW IS REVIVED THROUGH `BookSeat`, NEVER RE-INSERTED. The
     * unique index refuses the second INSERT and the caller is told «لديك مقعد
     * محجوز في هذه الحصة بالفعل» about a seat they do not hold.
     */
    public function claimOne(ClassSession $session, User $student, ?SessionBooking $existing): ?string
    {
        return $this->decide(
            $session,
            $student,
            $existing,
            fn (): mixed => $this->seats->claimGrantedSeat($session, $student),
        );
    }

    /**
     * The same decision for a member who pays by CREDIT rather than by
     * subscription (052).
     *
     * ⚠️ ONE DIFFERENCE, AND IT IS THE DOOR. A subscriber's seat is already paid
     * for, so `claimGrantedSeat()` waives the FR-041 unlock gate — refusing them
     * a seat their month bought over an unfinished worksheet would be taking back
     * something they own. A member has bought nothing yet (booking charges no
     * credits; `ChargeSeatsOnDelivery` does, at delivery), so the question is the
     * one their own «احجز» button asks: `BookSeat::handle()`, which is
     * `openingRefusal()` — enrolment, freeze, balance AND the unlock gate. Two
     * doors for one act is how a control on a screen and the automation beside it
     * come to disagree; this way the automation can seat exactly whom the student
     * could have seated themselves.
     *
     * ⚠️ A `Released` ROW IS STILL REVIVED THROUGH `reviveReleasedSeat()`, which
     * asks `refusalReason()` and therefore waives the gate for a member too. That
     * is deliberate and narrow: the row exists because a seat was taken away by a
     * decision that was not the student's — a transfer, a lapse — and giving it
     * back is not the same act as handing out the next one.
     *
     * @param  SessionBooking|null  $existing  the student's row for this session, if they have one
     * @return string|null the same three answers `claimOne()` gives
     */
    public function claimOneAsMember(ClassSession $session, User $student, ?SessionBooking $existing): ?string
    {
        return $this->decide(
            $session,
            $student,
            $existing,
            fn (): mixed => $this->seats->handle($session, $student),
        );
    }

    /**
     * @param  callable(): mixed  $open  the door a student with no row yet goes through
     */
    private function decide(ClassSession $session, User $student, ?SessionBooking $existing, callable $open): ?string
    {
        /*
        | ⚠️ `billable_seats !== null` means the count the teacher is paid on has
        | already been settled for this session, and it is never recomputed — it
        | answers a question about a moment that has passed. Seating somebody
        | after it puts a student in the room that nobody pays the teacher for
        | (FR-039أ · product decision 2026-09-05). The twin case — a session
        | scheduled inside its own cancellation window, whose count would freeze
        | at zero on the day it was created — is fixed at the source instead, in
        | `ClassSession::billableSeatsFreezeAt()` (FR-039ب).
        */
        if ($session->billable_seats !== null) {
            // Not «قبل تفعيل اشتراكك» any more: 052 sends credit-paying members
            // through here too, and a sentence naming a subscription would reach
            // somebody who has never held one.
            return 'أُغلق حساب مقاعد هذه الحصة، فلم يعد الحجز فيها ممكناً.';
        }

        if ($existing !== null) {
            return match ($existing->status) {
                BookingStatus::Booked => '',
                // FR-044: the student cancelled this one themselves. A booking
                // undone must not come back in the night — that is a button with
                // no effect, which is worse than no button.
                BookingStatus::CancelledInWindow, BookingStatus::CancelledLate => '',
                BookingStatus::Released => $this->attempt(
                    fn (): mixed => $this->seats->reviveReleasedSeat($session, $student),
                ),
            };
        }

        return $this->attempt($open);
    }

    /**
     * The group's lessons that can still be booked, bounded by the subscription
     * window when the caller knows one.
     *
     * @return EloquentCollection<int, ClassSession>
     */
    private function futureSessionsOf(int $workspaceId, int $courseId, int $cohortId, ?DateTimeInterface $windowEnd): EloquentCollection
    {
        $query = ClassSession::query()
            ->withoutWorkspaceScope()
            /*
            | The four columns together, in this order, so the read walks
            | `class_sessions_cohort_timeline_index (workspace_id, course_id,
            | cohort_id, starts_at)` rather than filtering a scan. The workspace is
            | named explicitly because this runs on a worker, where the global
            | scope adds no condition at all.
            */
            ->where('workspace_id', $workspaceId)
            ->where('course_id', $courseId)
            ->where('cohort_id', $cohortId)
            /*
            | Only sessions that accept a booking at all. A suspended one (a
            | freeze period covers it) or a cancelled one would otherwise reach
            | `assertBookable()` and come back as a refusal we would report to the
            | student — about a lesson their teacher deliberately called off.
            */
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->where('starts_at', '>', now());

        if ($windowEnd !== null) {
            /*
            | ⚠️ `< windowEnd + 1 day`, NEVER `<= windowEnd`. The subscription's end
            | is a DATE and `starts_at` a timestamp, so the second form drops every
            | session after midnight on the last day the student paid for — the
            | boundary that has already cost `FreezePeriod::covering()` and a
            | settlement close their own fixes.
            */
            $query->where('starts_at', '<', Carbon::parse($windowEnd)->startOfDay()->addDay());
        }

        return $query->orderBy('starts_at')->get();
    }

    /**
     * @param  array<int, int|string>  $sessionIds
     * @return EloquentCollection<int, SessionBooking>
     */
    private function existingBookings(array $sessionIds, User $student): EloquentCollection
    {
        return SessionBooking::query()
            ->withoutWorkspaceScope()
            ->whereIn('class_session_id', $sessionIds)
            ->where('student_user_id', $student->getKey())
            ->get()
            ->keyBy('class_session_id');
    }

    /**
     * The seats one member of a group is owed, asked per session rather than
     * across a window (027 · FR-046).
     *
     * The activation path knows the subscription's end date and can bound the
     * read with it. A TRANSFER knows no such date — the membership move says
     * nothing about what the student bought — so the question becomes «is this
     * student's subscription live at THIS lesson's hour», which is exactly what
     * the directory answers. Same status table underneath, same revival of a
     * released row; only the bound differs.
     *
     * ⚠️ AND WITHOUT IT, FR-046 IS HALF A FEATURE. Moving A → B releases A's
     * seats today and books nothing in B, so the student who was moved BY THEIR
     * TEACHER ends the day with fewer seats than they started with and no message
     * anywhere saying so.
     *
     * @return array{booked: int, refused: list<array{uuid: string, title: string, starts_at: string, reason: string}>}
     */
    public function forMemberInCohort(int $workspaceId, User $student, int $courseId, int $cohortId): array
    {
        $sessions = $this->futureSessionsOf($workspaceId, $courseId, $cohortId, null);

        if ($sessions->isEmpty()) {
            return ['booked' => 0, 'refused' => []];
        }

        $existing = $this->existingBookings($sessions->modelKeys(), $student);

        $booked = 0;
        $refused = [];

        foreach ($sessions as $session) {
            $covered = $this->subscriptions->subscriberIdsAmong(
                [(int) $student->getKey()],
                $courseId,
                $session->type->value,
                $session->starts_at,
            );

            /*
            | ⚠️ THE SUBSCRIPTION PICKS THE DOOR; IT NO LONGER DECIDES WHETHER TO
            | OPEN ONE (052). Until then, a member whose month did not reach this
            | lesson was skipped — which meant every credit-paying student, i.e.
            | most of them, joined a group and was booked into nothing. Now they
            | go through the same door their own button uses.
            */
            $refusal = $covered === []
                ? $this->claimOneAsMember($session, $student, $existing->get($session->getKey()))
                : $this->claimOne($session, $student, $existing->get($session->getKey()));

            if ($refusal === null) {
                $booked++;

                continue;
            }

            if ($refusal === '') {
                continue;
            }

            $refused[] = [
                'uuid' => (string) $session->uuid,
                'title' => (string) $session->title,
                'starts_at' => $session->starts_at->toIso8601String(),
                'reason' => $refusal,
            ];
        }

        return ['booked' => $booked, 'refused' => $refused];
    }

    /**
     * @param  callable(): mixed  $claim
     * @return string|null the refusal to report, `''` for one that must not be
     *                     reported, or null on success
     */
    private function attempt(callable $claim): ?string
    {
        try {
            $claim();

            return null;
        } catch (DomainException $e) {
            /*
            | A freeze is a holiday the teacher declared, not a seat we failed to
            | get. Reporting it would tell the student their subscription is
            | broken because their teacher is on leave — and this Action re-runs,
            | so that message would arrive again on every pass.
            */
            return $e->getMessage() === BookingEligibility::FROZEN_REFUSAL ? '' : $e->getMessage();
        }
    }
}
