<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\SessionClash;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SessionCreditHolds;
use App\Shared\Contracts\SubscriptionDirectory;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Edits a scheduled session.
 *
 * The type is frozen once anybody has booked (FR-001ب). Pricing in 006 and
 * payout in 014 differ by type in kind, so flipping a booked group session to
 * individual silently reprices seats people already hold.
 */
class UpdateClassSession extends Action
{
    public function __construct(
        private readonly SubscriptionDirectory $subscriptions,
        private readonly SessionCreditHolds $holds,
        private readonly CancelBooking $bookings,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(ClassSession $session, array $attributes): ClassSession
    {
        if ($session->status->isTerminal()) {
            throw new DomainException('لا يمكن تعديل حصة منتهية أو ملغاة.');
        }

        /*
        | ٠٣٥ · T075 · FR-029 — THE CLOCK AND THE DURATION ARE FROZEN WHILE THE
        | LESSON IS BEING TAUGHT.
        |
        | The existing guard above covers a TERMINAL session and stops there, so
        | this was open the whole time the room was live — and the duration is not
        | a label. The stay bar is half of it (٠٣٥ · FR-005) and is read when the
        | register closes, so a teacher who drops a sixty-minute lesson to ten in
        | its fiftieth minute moves that bar from thirty minutes to five: everyone
        | who looked in briefly is charged a credit, and the teacher is paid for
        | every one of them. `starts_at` is the same lever from the other end — it
        | also derives the cancellation deadline, which is what decides who is
        | exempt when the hour is judged.
        |
        | ⛔ THE CONDITION IS THE STATUS, NEVER `room_opened_at`. The two agree in a
        | real database, where only `OpenBroadcastRoom` writes `Live` and it writes
        | both in one statement — but fixtures across the suite stamp `live` with
        | no timestamp, so the second spelling turns forty existing tests into
        | claims about a lesson that never happened.
        |
        | ⛔ AND IT NAMES THE TWO FIELDS RATHER THAN LOCKING THE ACTION. There is a
        | third caller with no FormRequest above it: `DecideSessionRescheduleRequest`
        | reaches this Action directly with `starts_at`. A blanket refusal would
        | take the reschedule decision down with it — and a live session really is
        | one the decision may not move, so that call refuses here on purpose while
        | every other edit it makes goes through.
        */
        if ($session->status === ClassSessionStatus::Live) {
            foreach (['starts_at', 'duration_minutes'] as $frozen) {
                if (array_key_exists($frozen, $attributes)) {
                    throw new DomainException('لا يمكن تغيير موعد الحصة أو مدتها وهي جارية.');
                }
            }
        }

        if (isset($attributes['type'])) {
            $this->assertTypeMayChange($session, ClassSessionType::from((string) $attributes['type']));
        }

        if (isset($attributes['seats_total'])) {
            $this->assertSeatsNotBelowBooked($session, (int) $attributes['seats_total']);
        }

        /** @var array{0: CarbonImmutable, 1: CarbonImmutable}|null $window */
        $window = null;

        if (isset($attributes['starts_at']) || isset($attributes['duration_minutes'])) {
            $startsAt = isset($attributes['starts_at'])
                ? CarbonImmutable::parse((string) $attributes['starts_at'])->utc()
                : CarbonImmutable::instance($session->starts_at);
            $duration = (int) ($attributes['duration_minutes'] ?? $session->duration_minutes);

            $attributes['starts_at'] = $startsAt;
            $attributes['ends_at'] = $startsAt->addMinutes($duration);
            $attributes['duration_minutes'] = $duration;
            $window = [$startsAt, $startsAt->addMinutes($duration)];

            /*
            | ⚠️ THE TWO RULES SCHEDULING HAS ALWAYS ENFORCED, AND EDITING NEVER
            | DID. `ScheduleClassSession` refuses an overlap and a freeze period;
            | this action moved `starts_at` with neither check, so the rule held
            | while a session was created and evaporated the moment one was
            | moved — which is how one group's Saturday lands on top of another
            | group's, two rooms of students told to turn up to the same hour and
            | nothing anywhere saying so. One spelling now, in {@see SessionClash}.
            */
            SessionClash::assertNotFrozen($startsAt);
        }

        /*
        | ⚠️ THE CLAIM AND THE SAVE ARE ONE TRANSACTION. The overlap check is a
        | claim on the teacher's calendar, and its row lock is what keeps a second
        | move (two reschedule approvals, a teacher's edit racing one) from landing
        | on the same hour before this row commits.
        */
        // Read BEFORE the save: `getOriginal()` is re-synced by it.
        $previousStart = CarbonImmutable::instance($session->starts_at);

        DB::transaction(function () use ($session, $attributes, $window): void {
            if ($window !== null) {
                SessionClash::assertFree(
                    (int) $session->teacher_profile_id,
                    $window[0],
                    $window[1],
                    (int) $session->getKey(),
                );
            }

            $session->fill($attributes)->save();
        });

        /*
        | ⚠️ A NEW TIME IS OWED A NEW REMINDER. The mark says «this seat was
        | reminded of THIS start»; left in place after a move, nobody is reminded
        | before the new time. Cleared here because every reschedule path —
        | the teacher's edit and `DecideSessionRescheduleRequest` — comes through
        | this Action. Unscoped: the sweep reads the seat, not the context.
        */
        if ($session->wasChanged('starts_at')) {
            SessionBooking::query()
                ->withoutWorkspaceScope()
                ->where('class_session_id', $session->getKey())
                ->whereNotNull('reminded_at')
                ->update(['reminded_at' => null]);

            $this->rearmSeatFreeze($session);

            $this->releaseSeatsMovedPastTheirSubscription($session, $previousStart);
        }

        return $session->refresh();
    }

    /**
     * ⛔ A SEAT A SUBSCRIPTION PAID FOR, MOVED PAST THE SUBSCRIPTION'S END, IS
     * NOBODY'S (audit 2026-09-27).
     *
     * The automatic claim books a subscriber with NO credit hold (027 · FR-041),
     * because the subscription pays. Moved to a day after the subscription ends
     * — a teacher's edit, or an accepted reschedule request, which comes through
     * here — the seat stayed booked with no hold and no subscription behind it,
     * and the charge billed it −1 with the floor off: a debt for a lesson the
     * student never booked on credit.
     *
     * So after a move, a seat that was covered at the OLD start, has no open
     * hold, and is not covered at the NEW start is released — `Released`, never
     * billable. Nothing else moves: a credit-funded seat keeps its hold (the
     * reschedule rule in `SessionCreditHolds` — a moved hold rides along), and a
     * seat still covered at the new time (a renewal, a second plan) stays.
     */
    private function releaseSeatsMovedPastTheirSubscription(ClassSession $session, CarbonImmutable $previousStart): void
    {
        if ($session->course_id === null) {
            return;
        }

        $seats = SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('status', BookingStatus::Booked->value)
            ->get();

        if ($seats->isEmpty()) {
            return;
        }

        $studentIds = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $seats->pluck('student_user_id')->all(),
        )));

        $coveredBefore = $this->subscriptions->subscriberIdsAmong(
            $studentIds,
            (int) $session->course_id,
            $session->type->value,
            $previousStart,
        );

        if ($coveredBefore === []) {
            return;
        }

        $coveredAfter = $this->subscriptions->subscriberIdsAmong(
            $coveredBefore,
            (int) $session->course_id,
            $session->type->value,
            $session->starts_at,
        );

        foreach ($seats as $seat) {
            $studentId = (int) $seat->student_user_id;

            if (! in_array($studentId, $coveredBefore, true) || in_array($studentId, $coveredAfter, true)) {
                continue;
            }

            if ($this->holds->openHoldSessionIds($studentId, [(int) $session->getKey()]) !== []) {
                continue;
            }

            $this->bookings->release($seat, 'نُقلت الحصة إلى ما بعد انتهاء اشتراكك.');
        }
    }

    /**
     * ⛔ A NEW TIME IS OWED A NEW SEAT COUNT, or the money and the pay disagree.
     *
     * `FreezeBillableSeatsJob` is dispatched once, at scheduling, delayed to the
     * ORIGINAL deadline. Moved earlier, the session closed with `billable_seats`
     * still null — `ChargeSessionSeats` read that as «unknown» and charged every
     * seat holder, while `AccrueTeachingUnits` read it as an empty room and paid
     * the teacher nothing. Moved later, the count froze days early and every
     * booking after it went unbilled.
     *
     * So the count is cleared and a job armed for the new deadline; the old job
     * still fires, and either finds the count already frozen or finds the window
     * still open and leaves (see the job).
     */
    private function rearmSeatFreeze(ClassSession $session): void
    {
        $session->forceFill([
            'billable_seats' => null,
            'seats_frozen_at' => null,
            'interruption_note' => $session->interruption_note === 'zero_attendance' ? null : $session->interruption_note,
        ])->save();

        FreezeBillableSeatsJob::dispatch((int) $session->getKey(), $session->starts_at->getTimestamp())
            ->delay($session->billableSeatsFreezeAt(now()));
    }

    private function assertTypeMayChange(ClassSession $session, ClassSessionType $type): void
    {
        if ($type === $session->type) {
            return;
        }

        if ($session->seats_taken > 0) {
            throw new DomainException('لا يمكن تغيير نوع الحصة بعد وجود حجز فيها.');
        }
    }

    private function assertSeatsNotBelowBooked(ClassSession $session, int $seats): void
    {
        if ($seats < $session->seats_taken) {
            throw new DomainException('عدد المقاعد أقل من عدد المحجوز فعلاً.');
        }
    }
}
