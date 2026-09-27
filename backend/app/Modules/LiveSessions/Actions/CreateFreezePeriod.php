<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Events\SessionCancelled;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionCreditHolds;
use App\Shared\Support\CountedNoun;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * A stretch of days where nothing counts (FR-039).
 *
 * The period itself writes nothing to attendance rows, counters or streaks — it
 * is *read* by scheduling, booking and the counting jobs (research §R11). That
 * is the whole reason resuming afterwards cannot fail: FR-043 asks that counters
 * come back with their previous values, and the only way to guarantee that is
 * never to have moved them. There is no resumption code in this module because
 * there is nothing to undo.
 *
 * What it does write is the sessions already on the calendar inside it. Those
 * are suspended, not deleted (FR-040): a deleted session takes its register, its
 * bookings and any question about them with it, and a family asking "what
 * happened to Tuesday" deserves an answer that still exists.
 *
 * A freeze on ONE student is narrower: it suspends that student's individual
 * sessions and takes only their seat out of a group one, which carries on for
 * everybody else (FR-039).
 *
 * The return value names what was suspended, which group sessions lost a seat,
 * and how many people were told. A freeze that quietly takes away twelve booked
 * hours is the failure mode the last edge case in the spec is about — the
 * teacher has to see the cost of the button they just pressed.
 */
class CreateFreezePeriod extends Action
{
    use DetectsConcurrencyErrors;

    /**
     * The reason written on the group seat a freeze on ONE student takes.
     *
     * ⚠️ A CONSTANT BECAUSE LIFTING THE FREEZE READS IT BACK
     * (`DeleteFreezePeriod::groupSeatsTakenBy()`): it is how the seat this freeze
     * took is told apart from one a lapse or a transfer took, and two spellings
     * of it would silently restore nothing.
     */
    public const SEAT_RELEASE_REASON = 'فترة تجميد';

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly WorkspaceContext $context,
        private readonly SessionCreditHolds $holds,
        private readonly CancelBooking $bookings,
        private readonly SessionSettings $settings,
    ) {}

    /**
     * @return array{period: FreezePeriod, suspended: list<ClassSession>, released: list<ClassSession>, notified: int}
     */
    public function handle(
        User $actor,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        ?User $student = null,
        ?string $reason = null,
    ): array {
        if ($endsOn->lessThan($startsOn)) {
            throw new DomainException('تاريخ نهاية التجميد قبل بدايته.');
        }

        /*
        | ⛔ A FREEZE STARTS TODAY OR LATER (audit 2026-09-27). Dated into the
        | past, it counted against the month of its `starts_on` — last month,
        | whose ceiling nobody was watching any more — and `FreezePeriodChanged`
        | extended every subscription it touched by days the student had already
        | had. The day is the PLATFORM's (`SessionSettings::timezone()`): both
        | dates were picked off the platform calendar.
        */
        $today = CarbonImmutable::now($this->settings->timezone())->toDateString();

        if ($startsOn->toDateString() < $today) {
            throw new DomainException('لا يجوز أن تبدأ فترة التجميد في يومٍ مضى.');
        }

        // NFR-001أ — a teacher may not act on, or learn anything about, someone
        // with no active enrolment in their own workspace. Without this the uuid
        // is an identity probe: pass any user's and the response comes back
        // carrying their name.
        //
        // In the Action rather than the FormRequest, because the panel and the
        // seeders come through this door too (Constitution II) — and because
        // `exists:users,uuid` answers a different question entirely.
        if ($student !== null && ! $this->enrollments->hasActiveEnrollmentInWorkspace(
            $student,
            (int) $this->context->id(),
        )) {
            throw new DomainException('هذا الطالب ليس من طلابك.');
        }

        /*
        | Owner decision 2026-09-27: one period runs at most `freezeMaxDays()`
        | days, counted INCLUSIVELY — the same reading `covering()` gives the two
        | columns (`ends_on` is the last frozen day), so a period that starts and
        | ends on one date is one day. Arithmetic on the two dates, no clock: both
        | were picked off the platform calendar and are already platform days.
        |
        | In the Action and not only in the FormRequest, because this is the one
        | door the API, the seeders and any future panel share (Constitution II).
        */
        $maxDays = $this->settings->freezeMaxDays();
        $days = (int) $startsOn->startOfDay()->diffInDays($endsOn->startOfDay()) + 1;

        if ($days > $maxDays) {
            throw new DomainException('لا يجوز أن تتجاوز فترة التجميد الواحدة '.CountedNoun::of($maxDays, self::DAYS_OBJECT).'.');
        }

        $period = $this->claimMonthlySlot($actor, $startsOn, $endsOn, $student, $reason);

        [$suspended, $released, $notified] = $this->suspendSessionsIn($period);

        return ['period' => $period, 'suspended' => $suspended, 'released' => $released, 'notified' => $notified];
    }

    /**
     * «يوم» as the object of «تتجاوز» — «تتجاوز يومين» · «تتجاوز ٣٠ يوماً».
     *
     * @var array{one: string, two: string, few: string, many: string, other: string}
     */
    private const DAYS_OBJECT = [
        'one' => 'يوماً واحداً',
        'two' => 'يومين',
        'few' => 'أيام',
        'many' => 'يوماً',
        'other' => 'يوم',
    ];

    /**
     * Writes the period — unless its month already holds the ceiling for this
     * scope.
     *
     * Owner decision 2026-09-27: at most `freezeMaxPerMonth()` periods may START
     * in one calendar month of the platform zone, per scope — the whole
     * workspace (`student_user_id IS NULL`), or one student in it. The month is
     * read off `starts_on` itself, which is already a platform day.
     *
     * ⛔ COUNTED IN `freeze_period_starts`, NEVER IN `freeze_periods`. Lifting a
     * freeze deletes its period row, so a ceiling counted there gave the slot
     * back on every lift and freeze → lift → freeze walked past it. A lifted
     * freeze still counts (owner decision 2026-09-27); the ledger row is written
     * here, once, and nothing deletes it.
     *
     * ⚠️ SERIALISED ON THE WORKSPACE ROW BEFORE IT COUNTS. A double-tapped
     * «تجميد الفترة» is exactly two concurrent requests, and a bare count then
     * insert lets both read one start and both write a second.
     *
     * ⛔ UNTIL 2026-09-27 THIS WAS `INSERT … SELECT … WHERE (SELECT COUNT(*) …) < ?`
     * AND ITS DOCBLOCK CALLED THAT ATOMIC. IT IS NOT. A conditional UPDATE is
     * safe because both requests contend on ONE EXISTING ROW; a count of rows
     * that do not exist yet has none to contend on. On MySQL under REPEATABLE
     * READ both requests could read the count below the ceiling, or deadlock on
     * the gap locks the subquery takes — which reached the teacher as a 500.
     *
     * So the transaction's first statement writes the scope's parent row
     * (`workspaces`, for both scopes: a workspace's freezes are rare, and
     * serialising all of them costs nothing). InnoDB holds that row's lock until
     * commit, so the second request waits there until the first has committed
     * its ledger row, and only then counts — with a plain read whose snapshot
     * opens AFTER the lock was granted, so it sees that row.
     *
     * ⚠️ WHICH IS WHY THE GATE MUST STAY THE FIRST STATEMENT OF AN OUTERMOST
     * TRANSACTION. A read hoisted above it, or a caller wrapping this in a
     * transaction that already read something, pins a snapshot taken before
     * the winner committed and the ceiling is open again. A deadlock is retried
     * (`attempts: 3`) and, if it persists, refused as «حاول مرة أخرى» — never a
     * 500, and never the ceiling message, since the teacher did not reach it.
     *
     * The period itself is then written through the model, in the same
     * transaction, so `HasUuid`, `BelongsToWorkspace` and the `created` hook
     * (`FreezePeriodChanged`, which spec 011 extends subscriptions from) all run
     * as they always did.
     */
    private function claimMonthlySlot(
        User $actor,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        ?User $student,
        ?string $reason,
    ): FreezePeriod {
        $workspaceId = $this->context->id();

        if ($workspaceId === null) {
            throw new DomainException('اختر مساحة العمل أولاً.');
        }

        $limit = $this->settings->freezeMaxPerMonth();
        $studentId = $student?->getKey();

        try {
            return DB::transaction(
                fn (): FreezePeriod => $this->claimInsideGate($actor, $startsOn, $endsOn, $reason, $workspaceId, $limit, $studentId),
                attempts: 3,
            );
        } catch (PDOException $e) {
            // A deadlock or lock wait the retries could not clear. Neither is
            // the ceiling — the teacher did not reach it — and neither is a 500.
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw new DomainException('تعذّر تسجيل فترة التجميد الآن. حاول مرة أخرى.');
        }
    }

    /**
     * The body of {@see claimMonthlySlot()}'s transaction. The gate is its FIRST
     * statement — nothing may be read above it.
     */
    private function claimInsideGate(
        User $actor,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        ?string $reason,
        int $workspaceId,
        int $limit,
        mixed $studentId,
    ): FreezePeriod {
        // `SET id = id` changes nothing and still takes the row's exclusive
        // lock, held until commit. Its affected-row count means nothing (MySQL
        // reports CHANGED rows), so it is not read.
        DB::update('UPDATE workspaces SET id = id WHERE id = ?', [$workspaceId]);

        // `whereNull()` for the workspace scope, never `= ?` with a null
        // binding: `student_user_id = NULL` matches no row at all, and the
        // ceiling would never bite on the scope teachers actually use.
        $started = DB::table('freeze_period_starts')
            ->where('workspace_id', $workspaceId)
            ->when(
                $studentId === null,
                fn ($query) => $query->whereNull('student_user_id'),
                fn ($query) => $query->where('student_user_id', $studentId),
            )
            ->where('starts_on', '>=', $startsOn->startOfMonth()->toDateString())
            ->where('starts_on', '<', $startsOn->startOfMonth()->addMonthNoOverflow()->toDateString())
            ->count();

        if ($started >= $limit) {
            throw new DomainException(
                'لا تبدأ في الشهر الواحد أكثر من '
                .CountedNoun::of($limit, ['one' => 'فترة تجميد واحدة', 'two' => 'فترتي تجميد', 'few' => 'فترات تجميد', 'many' => 'فترة تجميد', 'other' => 'فترة تجميد'])
                .($studentId === null ? '' : ' لهذا الطالب')
                .' — والفترة التي رُفعت تُحسب أيضاً. اختر بداية في شهر آخر.',
            );
        }

        DB::table('freeze_period_starts')->insert([
            'workspace_id' => $workspaceId,
            'student_user_id' => $studentId,
            'starts_on' => $startsOn->toDateString(),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        return FreezePeriod::query()->create([
            'student_user_id' => $studentId,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'reason' => $reason,
            'created_by' => $actor->getKey(),
        ]);
    }

    /**
     * @return array{0: list<ClassSession>, 1: list<ClassSession>, 2: int}
     */
    private function suspendSessionsIn(FreezePeriod $period): array
    {
        $sessions = ClassSession::query()
            ->where('status', ClassSessionStatus::Scheduled)
            ->startingInside($period)
            // A freeze on one student reaches only the sessions that student
            // holds a seat in — the teacher's other classes carry on (FR-039).
            ->when(
                $period->student_user_id !== null,
                fn ($query) => $query->whereHas(
                    'bookings',
                    fn ($booking) => $booking
                        ->where('student_user_id', $period->student_user_id)
                        ->where('status', BookingStatus::Booked),
                ),
            )
            ->get();

        $reason = $period->reason === null ? 'فترة تجميد' : 'فترة تجميد: '.$period->reason;
        $notified = 0;
        $suspended = [];
        $released = [];

        foreach ($sessions as $session) {
            /*
            | ⛔ A FREEZE ON ONE STUDENT TAKES THAT STUDENT'S SEAT, NEVER THE ROOM.
            |
            | The selection above already knew this — it asks for the sessions the
            | student holds a seat in — and `suspend()` then treated each one as
            | the whole class's: every booking released, `seats_taken = 0`, every
            | credit hold returned, and «لن تُعقد» sent to every classmate and
            | their guardians about a lesson that was still being taught. One
            | family's holiday called off a whole group.
            |
            | An individual session is that student's alone, so suspending it IS
            | taking their seat and nothing more — it keeps the old behaviour. A
            | group session loses exactly one seat, through the system-release
            | door `CancelBooking::release()` already is: `Released`, not billable,
            | one decrement, and only this student's credit hold returned.
            */
            if ($period->student_user_id !== null && $session->type === ClassSessionType::Group) {
                if ($this->releaseOneSeat($session, (int) $period->student_user_id, $reason)) {
                    $notified++;
                    $released[] = $session;
                }

                continue;
            }

            $told = $this->suspend($session, $reason);

            if ($told !== null) {
                $notified += $told;
                $suspended[] = $session;
            }
        }

        return [$suspended, $released, $notified];
    }

    /**
     * Takes one student's seat out of a group session that carries on.
     *
     * Says whether this freeze actually took the seat: `release()` claims the
     * row with a conditional UPDATE and returns quietly when somebody else got
     * there first, and then nobody is told about it a second time.
     */
    private function releaseOneSeat(ClassSession $session, int $studentUserId, string $reason): bool
    {
        $booking = $session->bookings()
            ->where('student_user_id', $studentUserId)
            ->where('status', BookingStatus::Booked)
            ->first();

        if ($booking === null) {
            return false;
        }

        $fresh = $this->bookings->release($booking, self::SEAT_RELEASE_REASON);

        // `release()` hands back the row as it now stands, so `Released` alone
        // does not say who released it: a system sweep that got there a moment
        // earlier leaves the same status under its own reason. Only a seat this
        // freeze took is announced as this freeze's news.
        if ($fresh->status !== BookingStatus::Released || $fresh->cancellation_reason !== self::SEAT_RELEASE_REASON) {
            return false;
        }

        // The same event as a suspension, addressed to ONE person: from that
        // seat's point of view the hour will not happen — for them — and the
        // reason line says why. Every classmate hears nothing, because nothing
        // happened to them.
        SessionCancelled::dispatch($session, $reason, [$studentUserId]);

        return true;
    }

    /**
     * Suspends the whole session. Null when it had already moved on.
     */
    private function suspend(ClassSession $session, string $reason): ?int
    {
        /** @var list<int>|null $seatHolderIds */
        $seatHolderIds = null;

        DB::transaction(function () use ($session, &$seatHolderIds): void {
            /*
            | The transition is the claim — one conditional UPDATE. The query that
            | selected this session read `scheduled` a moment ago, and the room
            | may have opened since: suspending a live lesson would release the
            | seats of students sitting in it. The loser writes nothing.
            */
            $claimed = ClassSession::query()->withoutWorkspaceScope()
                ->whereKey($session->getKey())
                ->where('status', ClassSessionStatus::Scheduled->value)
                ->update([
                    'status' => ClassSessionStatus::Suspended->value,
                    'seats_taken' => 0,
                ]);

            if ($claimed !== 1) {
                return;
            }

            // Read before the release, for the same reason CancelClassSession
            // does: a listener running afterwards cannot tell a seat taken away
            // from a seat given back weeks ago.
            $seatHolderIds = array_values($session->bookings()
                ->where('status', BookingStatus::Booked)
                ->pluck('student_user_id')
                ->map(fn ($id): int => (int) $id)
                ->all());

            // Released, not cancelled: the students did nothing, and filing it
            // against them would put a mark on the wrong person.
            $session->bookings()
                ->where('status', BookingStatus::Booked)
                ->update([
                    'status' => BookingStatus::Released,
                    'is_billable' => false,
                    'cancelled_at' => now(),
                    'cancellation_reason' => self::SEAT_RELEASE_REASON,
                ]);

            // ٠٣٥ · T060 — a suspended hour holds nobody's credit. The seats are
            // taken away by a decision that was not the student's, so keeping
            // their credits frozen would be charging them for the teacher's
            // holiday — in the one currency they cannot see moving.
            $this->holds->release((int) $session->getKey());
        });

        if ($seatHolderIds === null) {
            return null;
        }

        // The in-memory model does not learn about a conditional UPDATE, and the
        // response renders it.
        $session->forceFill([
            'status' => ClassSessionStatus::Suspended,
            'seats_taken' => 0,
        ])->syncOriginal();

        // Same event as an outright cancellation, because from a seat's point of
        // view it is the same news. Dispatched after the transaction so a message
        // never describes a rollback.
        SessionCancelled::dispatch($session, $reason, $seatHolderIds);

        return count($seatHolderIds);
    }
}
