<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Modules\LiveSessions\Support\SessionClash;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SubscriptionDirectory;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Lifts a freeze — and hands back the future sessions it suspended.
 *
 * ⛔ `Suspended` HAD NO WAY OUT. Deleting the period used to remove the row and
 * nothing else, so every session it had suspended stayed suspended for ever: no
 * booking can reach it (`acceptsBookings()` is `Scheduled` alone), no Action
 * moves it back, and `SessionClash` ignores it — so the teacher's calendar showed
 * an hour as free that was still holding a dead session. A freeze lifted a day
 * after it was declared cost every lesson inside it, permanently.
 *
 * What comes back HERE for a SUSPENDED session is the session, never its seats.
 * Those were released and every holder was told «لن تُعقد»; a revived session is
 * bookable again, and a credit-paying student who still wants the hour books it
 * from «احجز» (`BookSeat::claim()` revives their released row). ⚠️ A GROUP
 * LESSON THAT WAS NEVER SUSPENDED IS DIFFERENT: a freeze on one student took that
 * student's seat alone while the lesson carried on, so lifting the freeze gives
 * the seat back ({@see self::restoreGroupSeats()}) — to a member who pays by
 * credit or bought the course, through the automation's own atomic revival, and
 * with a notice when the room filled up meanwhile.
 * ⚠️ A GROUP SUBSCRIBER IS THE EXCEPTION, and it is not decided here:
 * the delete moves their subscription's end, and `EffectiveSubscriptionEnd`
 * re-claims their seats up to the new end and releases the ones past it (owner
 * decision 2026-09-25, option 3) — their month paid for those hours, and FR-039
 * takes a subscriber's seats «without the student pressing anything».
 *
 * Three things keep a session suspended, and each is reported rather than
 * swallowed:
 *
 * - it has already started — lifting a freeze does not reopen yesterday;
 * - another freeze still covers it — lifting one of two overlapping periods must
 *   not undo the other;
 * - the teacher has put something else in that hour since — the slot looked free
 *   while the session was suspended, and reviving it would double-book them.
 */
class DeleteFreezePeriod extends Action
{
    public function __construct(
        private readonly BookSeat $seats,
        private readonly SubscriptionDirectory $subscriptions,
        private readonly DispatchNotification $notify,
        private readonly SessionSettings $settings,
    ) {}

    /**
     * @return array{restored: list<ClassSession>, kept: list<ClassSession>}
     */
    public function handle(FreezePeriod $period): array
    {
        // Selected BEFORE the delete, while the period can still describe which
        // sessions were its own.
        $candidates = $this->suspendedBy($period);
        $seatsTaken = $this->groupSeatsTakenBy($period);

        /*
        | ⚠️ ONE TRANSACTION AROUND THE DELETE AND EVERY REVIVAL. The delete
        | announces `FreezePeriodChanged`, and its listener
        | (`RecomputeSubscriptionEnds`, `ShouldQueueAfterCommit`) re-claims each
        | subscriber's group seats up to the new end (owner decision 2026-09-25,
        | option 3b). With no transaction open that job is pushed the instant the
        | row goes — while every session this freeze suspended is still
        | `Suspended`, which the claim skips — so a workspace freeze lifted gave
        | nobody their seats back. Inside it, the job waits until the sessions it
        | is meant to fill are bookable again. Each revival keeps its own inner
        | transaction (a savepoint here), so one refused revival still leaves the
        | others standing.
        */
        return DB::transaction(function () use ($period, $candidates, $seatsTaken): array {
            // Through the model, never a query: `booted()` announces the delete,
            // and spec 011 takes back the subscription extension this period
            // granted.
            $period->delete();

            $restored = [];
            $kept = [];

            foreach ($candidates as $session) {
                if ($this->restore($session)) {
                    $restored[] = $session;
                } else {
                    $kept[] = $session;
                }
            }

            if ($period->student_user_id !== null && $seatsTaken !== []) {
                $this->restoreGroupSeats((int) $period->student_user_id, $seatsTaken);
            }

            if ($period->student_user_id === null) {
                $this->announceReopenedGroupLessons($restored);
            }

            return ['restored' => $restored, 'kept' => $kept];
        });
    }

    /**
     * The group lessons this period took ONE seat out of — the student's — while
     * they carried on for everybody else (`CreateFreezePeriod::releaseOneSeat()`).
     *
     * Recognised by the row that freeze left: this student's booking, `Released`,
     * under the freeze's own reason. Only lessons still ahead and still
     * scheduled; one that happened while the seat was out is not reopened.
     *
     * @return list<ClassSession>
     */
    private function groupSeatsTakenBy(FreezePeriod $period): array
    {
        if ($period->student_user_id === null) {
            return [];
        }

        return array_values(ClassSession::query()
            ->where('status', ClassSessionStatus::Scheduled)
            ->where('type', ClassSessionType::Group)
            ->startingInside($period)
            ->where('starts_at', '>', now())
            ->whereHas(
                'bookings',
                fn ($booking) => $booking
                    ->where('student_user_id', $period->student_user_id)
                    ->where('status', BookingStatus::Released)
                    ->where('cancellation_reason', CreateFreezePeriod::SEAT_RELEASE_REASON),
            )
            ->orderBy('starts_at')
            ->get()
            ->all());
    }

    /**
     * Gives the student back the group seats the lifted freeze took.
     *
     * ⛔ UNTIL THIS, ONLY A SUBSCRIBER GOT THEIRS BACK. PR #230 re-claims a group
     * SUBSCRIBER's seats through their subscription's moved end; a member who
     * pays by credit, or bought the course outright, had the seat released by
     * the freeze and nothing at all when it was lifted — and was never told.
     *
     * ⚠️ A SUBSCRIBER IS SKIPPED HERE, NOT SERVED TWICE. Their revival belongs to
     * `EffectiveSubscriptionEnd` (queued after this transaction commits), which
     * revives with no credit hold because the month paid for the hour; reviving
     * it here first would freeze a credit for somebody who holds none.
     *
     * ⚠️ THE REVIVAL IS `BookSeat::reviveReleasedSeat()` — the capacity claim and
     * the conditional `WHERE status = released` the automation already uses,
     * each in its own savepoint, so one full room does not undo the others. A
     * room that filled up while the seat was out is NOT forced: the seat stays
     * released, and the student (and the teacher, who can widen the capacity)
     * is told after the commit which lessons did not come back.
     *
     * @param  list<ClassSession>  $sessions
     */
    private function restoreGroupSeats(int $studentUserId, array $sessions): void
    {
        $student = User::query()->find($studentUserId);

        if ($student === null) {
            return;
        }

        $refused = [];

        foreach ($sessions as $session) {
            $startsAt = CarbonImmutable::instance($session->starts_at);

            // Another period over this student (or over everyone) still covers it.
            if (FreezePeriod::query()->covering($startsAt, $studentUserId)->exists()) {
                continue;
            }

            if ($session->course_id !== null && $this->subscriptions->subscriberIdsAmong(
                [$studentUserId],
                (int) $session->course_id,
                $session->type->value,
                $session->starts_at,
            ) !== []) {
                continue;
            }

            try {
                $this->seats->reviveReleasedSeat($session, $student, subscriptionCovered: false);
            } catch (DomainException $e) {
                if ($e->getMessage() !== BookingEligibility::FROZEN_REFUSAL) {
                    $refused[] = $session;
                }
            }
        }

        if ($refused === []) {
            return;
        }

        // After the commit: a notice must never describe a revival that rolled
        // back, and this runs inside `handle()`'s transaction.
        DB::afterCommit(fn () => $this->announceUnrestored($student, $refused));
    }

    /**
     * A WORKSPACE freeze lifted: tells each non-subscriber whose group seat it
     * released that the lesson is back and may be booked again (owner decision
     * 2026-09-26).
     *
     * ⚠️ TOLD, NEVER BOOKED. A credit-paying student decides whether to spend a
     * credit on the hour; taking the seat for them would freeze one they may
     * already have planned elsewhere. Their row is `Released`, so «احجز» revives
     * it (`BookSeat::claim()`).
     *
     * ⚠️ A SUBSCRIBER IS NOT TOLD: their seat comes back by itself through
     * `EffectiveSubscriptionEnd` after this transaction commits, and «book it
     * yourself» would be a false instruction about a seat already theirs.
     *
     * ⚠️ ONE NOTICE PER STUDENT naming every lesson — a holiday's worth of
     * lessons is otherwise a dozen buzzes from one button — sent after the
     * commit, so it never describes a revival that rolled back.
     *
     * @param  list<ClassSession>  $restored
     */
    private function announceReopenedGroupLessons(array $restored): void
    {
        $group = array_values(array_filter(
            $restored,
            static fn (ClassSession $session): bool => $session->type === ClassSessionType::Group,
        ));

        if ($group === []) {
            return;
        }

        $sessions = collect($group)->keyBy(static fn (ClassSession $session): int => (int) $session->getKey());

        $released = DB::table('session_bookings')
            ->whereIn('class_session_id', $sessions->keys()->all())
            ->where('status', BookingStatus::Released->value)
            ->where('cancellation_reason', CreateFreezePeriod::SEAT_RELEASE_REASON)
            ->get(['class_session_id', 'student_user_id']);

        /** @var array<int, list<ClassSession>> $byStudent */
        $byStudent = [];

        foreach ($released as $row) {
            $session = $sessions->get((int) $row->class_session_id);
            $studentId = (int) $row->student_user_id;

            if ($session === null) {
                continue;
            }

            if ($session->course_id !== null && $this->subscriptions->subscriberIdsAmong(
                [$studentId],
                (int) $session->course_id,
                $session->type->value,
                $session->starts_at,
            ) !== []) {
                continue;
            }

            $byStudent[$studentId][] = $session;
        }

        if ($byStudent === []) {
            return;
        }

        DB::afterCommit(function () use ($byStudent): void {
            $students = User::query()->whereKey(array_keys($byStudent))->get()->keyBy('id');

            foreach ($byStudent as $studentId => $lessons) {
                $student = $students->get($studentId);

                if ($student === null) {
                    continue;
                }

                $this->notify->handle(new NotificationRequest(
                    recipient: $student,
                    type: NotificationType::SessionSeatReopened,
                    variables: ['sessions' => $this->describe($lessons)],
                    actionUrl: '/schedule',
                    subject: $student,
                    workspaceId: (int) $lessons[0]->workspace_id,
                ));
            }
        });
    }

    /**
     * «title (Y-m-d H:i)، …» in the platform's zone — the line both notices carry.
     *
     * @param  list<ClassSession>  $sessions
     */
    private function describe(array $sessions): string
    {
        return implode('، ', array_map(
            fn (ClassSession $session): string => sprintf(
                '%s (%s)',
                $session->title,
                CarbonImmutable::instance($session->starts_at)->setTimezone($this->settings->timezone())->format('Y-m-d H:i'),
            ),
            $sessions,
        ));
    }

    /**
     * «These seats did not come back», to the student and to the teacher.
     *
     * The type is the one the automatic booker already sends for a seat it could
     * not take (027 · FR-042): the same news, for the same two people who can act
     * on it, with a template that already exists on every database.
     *
     * @param  non-empty-list<ClassSession>  $sessions
     */
    private function announceUnrestored(User $student, array $sessions): void
    {
        $lines = $this->describe($sessions);

        $workspaceId = (int) $sessions[0]->workspace_id;

        $this->notify->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::SubscriptionSeatUnavailable,
            variables: ['student_name' => $student->name, 'sessions' => $lines],
            actionUrl: '/schedule',
            subject: $student,
            workspaceId: $workspaceId,
        ));

        $teacher = Workspace::query()->withoutGlobalScopes()->find($workspaceId)?->owner;

        if ($teacher === null) {
            return;
        }

        $this->notify->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::SubscriptionSeatUnavailable,
            variables: ['student_name' => $student->name, 'sessions' => $lines],
            actionUrl: '/manage/sessions',
            subject: $student,
            workspaceId: $workspaceId,
        ));
    }

    /**
     * The sessions this period suspended that have not happened yet.
     *
     * The same range `CreateFreezePeriod` suspended through (`startingInside`),
     * narrowed the same way it was: a workspace-wide period suspended every
     * session in it, a period on one student suspended only that student's
     * INDIVIDUAL sessions — a group one never stops for one student — and those
     * are recognised by the seat the freeze released.
     *
     * @return list<ClassSession>
     */
    private function suspendedBy(FreezePeriod $period): array
    {
        return array_values(ClassSession::query()
            ->where('status', ClassSessionStatus::Suspended)
            ->startingInside($period)
            ->where('starts_at', '>', now())
            ->when(
                $period->student_user_id !== null,
                fn ($query) => $query
                    ->where('type', ClassSessionType::Individual)
                    ->whereHas(
                        'bookings',
                        fn ($booking) => $booking
                            ->where('student_user_id', $period->student_user_id)
                            ->where('status', BookingStatus::Released),
                    ),
            )
            ->orderBy('starts_at')
            ->get()
            ->all());
    }

    private function restore(ClassSession $session): bool
    {
        $startsAt = CarbonImmutable::instance($session->starts_at);

        if ($this->stillFrozen($session, $startsAt)) {
            return false;
        }

        try {
            DB::transaction(function () use ($session, $startsAt): void {
                // The hour may have been given to something else while this
                // session sat suspended. Asked inside the transaction because the
                // clash check CLAIMS the teacher's calendar (see SessionClash).
                SessionClash::assertFree(
                    (int) $session->teacher_profile_id,
                    $startsAt,
                    CarbonImmutable::instance($session->ends_at),
                    (int) $session->getKey(),
                );

                // The claim: only a session still suspended and still in the
                // future moves. A second lift, or a cancellation in between,
                // leaves this one alone.
                $claimed = ClassSession::query()->withoutWorkspaceScope()
                    ->whereKey($session->getKey())
                    ->where('status', ClassSessionStatus::Suspended->value)
                    ->where('starts_at', '>', now())
                    ->update([
                        'status' => ClassSessionStatus::Scheduled->value,
                        // ⛔ A REVIVED SESSION IS OWED A NEW SEAT COUNT —
                        // `UpdateClassSession::rearmSeatFreeze()`'s rule, reached
                        // from a second door. `FreezeBillableSeatsJob` does not
                        // skip a suspended session, so one whose cancellation
                        // deadline passed during the freeze already carries
                        // `billable_seats = 0`, frozen for ever: everyone who
                        // books the revived hour would be taught unbilled, and
                        // the teacher paid for an empty room.
                        'billable_seats' => null,
                        'seats_frozen_at' => null,
                    ]);

                if ($claimed !== 1) {
                    throw new DomainException('تغيّرت حالة الحصة.');
                }

                // A suspended session holds no seat, so a generated 1:1 slot comes
                // back as nobody's — not as the frozen student's for ever.
                ClassSession::reopenEmptyIndividualSlot((int) $session->getKey());
            });
        } catch (DomainException) {
            return false;
        }

        $session->refresh();

        if ($session->interruption_note === 'zero_attendance') {
            // Written by the seat-count job that ran while the session was
            // suspended; it described an emptiness the freeze caused.
            $session->forceFill(['interruption_note' => null])->save();
        }

        // Armed for the session's own start, like every other arming: a revival
        // is a second birth, so a session revived inside its own cancellation
        // window settles its count when it starts.
        // `afterCommit`: this now runs inside `handle()`'s transaction, and a
        // worker reading the session before it commits would find it still
        // `Suspended`.
        FreezeBillableSeatsJob::dispatch((int) $session->getKey(), $session->starts_at->getTimestamp())
            ->delay($session->billableSeatsFreezeAt(now()))
            ->afterCommit();

        return true;
    }

    /**
     * Whether a period OTHER than the one being lifted still covers this hour.
     *
     * A workspace-wide period covers every session; a period on one student
     * covers the individual session whose seat that student held.
     */
    private function stillFrozen(ClassSession $session, CarbonImmutable $startsAt): bool
    {
        $studentUserId = null;

        if ($session->type === ClassSessionType::Individual) {
            $studentUserId = $session->bookings()
                ->where('status', BookingStatus::Released)
                ->latest('id')
                ->value('student_user_id');
        }

        return FreezePeriod::query()
            ->covering($startsAt, $studentUserId === null ? null : (int) $studentUserId)
            ->exists();
    }
}
