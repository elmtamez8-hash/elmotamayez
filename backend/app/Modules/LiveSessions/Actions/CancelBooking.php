<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Enums\SessionCanceller;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SessionCreditHolds;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Gives a seat up.
 *
 * A late cancellation is allowed and still charged (FR-010) — the deadline is
 * not a lock on the button, it is what the seat costs after it passes. Refusing
 * the cancellation instead would leave the student marked absent from a session
 * they told us they could not attend, which is a worse record of the same fact.
 *
 * The seat only returns to the pool when it was given up in time: a late
 * cancellation keeps `seats_taken` where it is, so the count frozen at the
 * deadline stays true (FR-060).
 */
class CancelBooking extends Action
{
    public function __construct(
        private readonly SessionCreditHolds $holds,
        private readonly CancelClassSession $sessions,
        private readonly DispatchNotification $notify,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(SessionBooking $booking, ?string $reason = null): SessionBooking
    {
        if ($booking->status !== BookingStatus::Booked) {
            throw new DomainException('هذا الحجز ملغى بالفعل.');
        }

        $session = $booking->classSession;

        if ($session === null) {
            // A booking whose session vanished is corrupt data, not a state to
            // limp along in: silently treating it as cancellable would decrement
            // a seat count on a row nobody can name.
            throw new DomainException('الحصة المرتبطة بهذا الحجز غير موجودة.');
        }

        $inWindow = now()->lessThan($session->cancellationDeadline());

        DB::transaction(function () use ($booking, $session, $reason, $inWindow): void {
            $claimed = $this->claim($booking, [
                'status' => $inWindow ? BookingStatus::CancelledInWindow : BookingStatus::CancelledLate,
                'is_billable' => ! $inWindow,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            if (! $claimed) {
                // Somebody else — a second tab, the system's release — moved this
                // seat between our read above and this write. Thrown from INSIDE
                // so nothing below runs: a second decrement would hand out a seat
                // that was never freed, and a second release would free a credit
                // the late-cancellation charge is still owed.
                throw new DomainException('هذا الحجز ملغى بالفعل.');
            }

            if ($inWindow) {
                ClassSession::query()->withoutWorkspaceScope()
                    ->whereKey($session->getKey())
                    ->where('seats_taken', '>', 0)
                    ->decrement('seats_taken');

                /*
                | ٠٣٥ · T060 — ⛔ THE IN-WINDOW ARM ALONE GIVES THE CREDIT BACK,
                | AND THE OTHER ONE MUST NOT. A late cancellation keeps the seat
                | and writes `is_billable = true`, and that is exactly the seat
                | `CloseClassSession` charges at the end (FR-008ج). Freeing its
                | credit here lets the student freeze it again for another hour
                | before the charge lands — and the floor is switched OFF in the
                | charge path on purpose, because a session that was delivered is
                | owed whether or not anyone can pay for it. So the balance goes
                | negative and the student reads as in arrears, over a button
                | that behaved correctly.
                */
                $this->holds->release((int) $session->getKey(), [(int) $booking->student_user_id]);

                // The seat went back to the pool, so a generated 1:1 slot is
                // nobody's again. Not in the late arm: that seat is still
                // counted, and still the student's to be charged for.
                ClassSession::reopenEmptyIndividualSlot((int) $session->getKey());
            }
        });

        if ($inWindow) {
            $this->cancelEmptyPrivateSession($session, (int) $booking->student_user_id, SessionCanceller::Student);
        }

        return $booking->refresh();
    }

    /**
     * Staff take a seat back — the teacher (or anyone holding `sessions.manage`)
     * pressing «إلغاء» on a student's booking (audit 2026-09-27).
     *
     * ⛔ `handle()` IS THE STUDENT'S DOOR AND ASSUMED IT WAS THE STUDENT PRESSING.
     * `SessionBookingPolicy::delete()` admits staff too, and through `handle()` a
     * teacher who removed a student after the deadline wrote `cancelled_late` +
     * `is_billable` — the STUDENT charged a credit for the teacher's decision —
     * and a private hour given back that way told the teacher «the student
     * cancelled». So staff go through the system's own arm: `Released`, never
     * billable, the frozen credit returned, whatever the clock says. Nobody
     * chose this moment but the teacher, and it is not the student's to pay for.
     *
     * A private 1:1 left empty is then called off as the TEACHER's cancellation
     * (it was their decision, so it does count on their record) with no «the
     * student cancelled» notice — the same hour-freeing the student's door does,
     * so the hour is not left scheduled with nobody in it.
     */
    public function releaseByStaff(SessionBooking $booking, ?string $reason = null): SessionBooking
    {
        if ($booking->status !== BookingStatus::Booked) {
            throw new DomainException('هذا الحجز ملغى بالفعل.');
        }

        $session = $booking->classSession;

        if ($session === null) {
            throw new DomainException('الحصة المرتبطة بهذا الحجز غير موجودة.');
        }

        $released = $this->release($booking, $reason ?? 'ألغى المدرّس هذا الحجز.');

        if ($released->status !== BookingStatus::Released) {
            // Somebody else moved it first — the student's own cancellation, a sweep.
            throw new DomainException('هذا الحجز ملغى بالفعل.');
        }

        $this->cancelEmptyPrivateSession($session, (int) $booking->student_user_id, SessionCanceller::Teacher);

        return $released;
    }

    /**
     * A private hour whose only student gave it back is an hour nobody is in —
     * so it is called off, through the teacher's own door (owner decision
     * 2026-09-26).
     *
     * ⚠️ BEFORE THIS the session stayed `scheduled` with no seat taken: the
     * teacher's hour stayed blocked (`SessionClash` counts it), the student
     * could book it back through «احجز» without a new request, and a fresh
     * request for the same slot was accepted as pending only for the teacher's
     * «قبول» to answer «لديك حصة أخرى في هذا الوقت».
     *
     * ⚠️ WHICH 1:1 SESSIONS. One that still carries a group after
     * `reopenEmptyIndividualSlot()` ran: a granted private request or a 1:1 the
     * teacher scheduled for one student. A slot GENERATED from availability
     * (`cohort_from_booking`) has just lost its group and is the teacher's
     * published hour again — it stays open for anyone to book.
     *
     * ⚠️ IN-WINDOW ONLY, and the caller decides that. A late cancellation keeps
     * its seat and its frozen credit because `CloseClassSession` charges it at
     * delivery; `CancelClassSession` releases every hold on the hour and would
     * never be closed, so calling it there would waive a charge the student
     * already owes — and would take away «تراجع عن الإلغاء».
     *
     * ⚠️ AFTER THE BOOKING'S TRANSACTION, NEVER INSIDE IT. `CancelClassSession`
     * dispatches `SessionCancelled` after its own transaction, and nested it
     * would announce a cancellation an outer rollback could still undo. Its seat
     * list is empty here (the one seat is already off `booked`), so nobody is
     * told the lesson is off — the only person who held it is the one who just
     * gave it up. Its claim is conditional on the status, so a session a
     * teacher moved meanwhile is left alone rather than failing the student's
     * cancellation, which has already happened.
     */
    private function cancelEmptyPrivateSession(ClassSession $session, int $studentUserId, SessionCanceller $by): void
    {
        $fresh = ClassSession::query()->withoutWorkspaceScope()->whereKey($session->getKey())->first();

        if ($fresh === null
            || $fresh->type !== ClassSessionType::Individual
            || $fresh->cohort_id === null
            || $fresh->seats_taken > 0
            || ! in_array($fresh->status, [ClassSessionStatus::Scheduled, ClassSessionStatus::Suspended], true)) {
            return;
        }

        try {
            // ⛔ WHO decided is recorded: a student's give-back is `Student` and
            // must not count against the teacher's public trust score, which it
            // did until 2026-09-27; a teacher's own release is `Teacher`.
            $this->sessions->handle(
                $fresh,
                $by === SessionCanceller::Student
                    ? 'ألغى الطالب حجزه في هذه الحصة الخاصة.'
                    : 'ألغى المدرّس الحجز الوحيد في هذه الحصة الخاصة.',
                $by,
            );
        } catch (DomainException) {
            // Moved by somebody else between the read and the claim — the
            // student's cancellation stands either way.
            return;
        }

        // The teacher is told only when it was not their own decision.
        if ($by === SessionCanceller::Student) {
            $this->tellTeacherTheHourIsFree($fresh, $studentUserId);
        }
    }

    /**
     * «ألغى سامي حصته الخاصة يوم … — الموعد متاح لك الآن» (owner decision
     * 2026-09-27).
     *
     * ⚠️ ONLY ONCE THE SESSION WAS ACTUALLY CALLED OFF. `SessionCancelled`
     * leaves this door with an empty seat list — the one holder is the student
     * who just gave it up — so before this nobody at all learned that the
     * teacher's hour had come back, and the teacher found out by looking.
     *
     * Through `DispatchNotification` with a type and nothing else: the channel is
     * the recipient's preference, never this Action's call. The hour is written
     * on the TEACHER's own clock, since the row is a UTC instant and the student
     * who cancelled may be in another country. After the commit, so the notice
     * never describes a cancellation an outer rollback undid.
     */
    private function tellTeacherTheHourIsFree(ClassSession $session, int $studentUserId): void
    {
        $teacher = $session->teacherProfile()->withoutGlobalScopes()->first()?->user;
        $student = User::query()->find($studentUserId);

        // A profile whose account is gone has nobody to tell, and neither is a
        // fault worth failing the student's cancellation over.
        if ($teacher === null || $student === null) {
            return;
        }

        DB::afterCommit(fn () => $this->notify->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::PrivateSessionCancelledByStudent,
            variables: [
                'student_name' => $student->name,
                'session_time' => $this->settings->formatFor($teacher, $session->starts_at),
            ],
            actionUrl: '/manage/sessions',
            subject: $student,
            workspaceId: (int) $session->workspace_id,
        )));
    }

    /**
     * The system takes a seat back — the student did nothing (027 · FR-045).
     *
     * ⚠️ A NAMED SECOND ENTRY, NOT A FLAG ON `handle()`. `handle($booking, $reason, true)`
     * says nothing at its call site and is read backwards by the first caller
     * written after it; this repository's `BookSeat` wrote that rule down for the
     * same shape.
     *
     * ⚠️ AND IT WRITES `Released`, WHICH IS THE WHOLE POINT. The enum already
     * carries the distinction and says why: «the student did nothing — their
     * eligibility lapsed and the system took the seat back». Filing a system
     * release under a cancellation puts a mark against somebody who cancelled
     * nothing — and worse, it made the seat unrecoverable for the automation:
     * the auto-booker skips a cancelled row on purpose (FR-044), so a student who
     * lapses, is released, then renews would never be booked into those sessions
     * again. Paid, unbooked, silent. (The student's own «احجز» revives either —
     * `BookSeat::claim()`.)
     *
     * ⚠️ AND THE DEADLINE IS NOT ASKED. `handle()` bills a late cancellation
     * because the student chose the moment; nobody chose this one. Taking the
     * seat away AND charging for it is the worst of both, and `isBillable()`
     * already answers false for `Released` — this keeps the column agreeing with
     * the enum.
     */
    public function release(SessionBooking $booking, string $reason): SessionBooking
    {
        if ($booking->status !== BookingStatus::Booked) {
            // Not an error: two sweeps can reach the same seat, and a release
            // that has already happened is the outcome asked for.
            return $booking;
        }

        DB::transaction(function () use ($booking, $reason): void {
            $claimed = $this->claim($booking, [
                'status' => BookingStatus::Released,
                'is_billable' => false,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            if (! $claimed) {
                // Not an error, for the reason at the top: the seat was already
                // given up by somebody else, which is the outcome asked for.
                return;
            }

            ClassSession::query()->withoutWorkspaceScope()
                ->whereKey($booking->class_session_id)
                ->where('seats_taken', '>', 0)
                ->decrement('seats_taken');

            // ⚠️ AND THIS ARM ALWAYS RELEASES, whatever the clock says. The
            // deadline is not asked here for the reason written above — nobody
            // chose this moment — and `is_billable` is false, so the seat is
            // never charged and its credit has nothing left to wait for.
            $this->holds->release(
                (int) $booking->class_session_id,
                [(int) $booking->student_user_id],
            );

            ClassSession::reopenEmptyIndividualSlot((int) $booking->class_session_id);
        });

        return $booking->refresh();
    }

    /**
     * Moves the seat off `booked` in ONE statement, and says whether it did.
     *
     * ⚠️ THE STATUS CHECK ABOVE READS THE IN-MEMORY MODEL, SO IT IS ADVISORY.
     * Two cancellations (a double tap, two tabs, the student and a system
     * release) both read `booked`, both wrote, and both decremented
     * `seats_taken` — one seat freed twice, handed to somebody the session had
     * no room for — and both released the credit hold. `WHERE status = booked`
     * makes the write the check: exactly one caller moves the row, and only that
     * caller touches the seat count or the credit. Never `lockForUpdate()`, a
     * no-op on SQLite.
     *
     * @param  array<string, mixed>  $values
     */
    private function claim(SessionBooking $booking, array $values): bool
    {
        $claimed = SessionBooking::query()
            ->withoutWorkspaceScope()
            ->whereKey($booking->getKey())
            ->where('status', BookingStatus::Booked->value)
            ->update(array_map(
                static fn (mixed $value): mixed => $value instanceof BookingStatus ? $value->value : $value,
                $values,
            ));

        return $claimed === 1;
    }
}
