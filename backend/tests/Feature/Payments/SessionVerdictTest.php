<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Actions\UnlockSessionContent;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\SessionUnlock;
use App\Modules\Payments\Support\BillingAuditSubjects;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\SessionContentAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T023 — FR-008د's TABLE, ONE ROW PER CASE, THREE ANSWERS EACH.
|
| ⛔ THE THREE COLUMNS ARE THE POINT AND NOT THE DECORATION. The spec writes the
| verdict as a table — student · teacher · content — because the three move
| INDEPENDENTLY and every cheap implementation collapses two of them:
|
|  · «charged» and «attended» are not the same number. The silent no-show is
|    charged and the teacher takes (FR-008ج), so a build that pays on attendance
|    hands the teacher nothing for the hour they sat alone waiting.
|  · «charged» and «open» ARE the same set, and that is a decision rather than a
|    coincidence: whoever paid for the hour receives it. `credit_verdict_at` is
|    the one column that answers both, which is why neither is re-derived.
|  · «exempt» and «open» are opposites. The student who gave notice keeps their
|    credit AND keeps the content shut until they spend one on purpose.
|
| ⚠️ WHAT MAKES THIS FILE FAIL ON A BUILD WITH NO FEATURE: every case below is
| ONE fixture shape — a delivered session with one seat — differing only in the
| fact under test. Before ٠٣٥ all six produce the identical answer: charged,
| paid, open. A green run here is six different answers to six different facts.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());

    /*
    | ⚠️ THE RATE HANGS OFF THE SESSION'S TEACHER PROFILE, NOT THE COURSE'S, and
    | the two are different rows here: `courseWithRate()` makes a profile of its
    | own while `billableSession()` finds the one belonging to `$owner`. A rate
    | on the wrong profile resolves to null, every unit is written worth ZERO,
    | and every «the teacher earns nothing» assertion in this file passes for the
    | wrong reason — which is the shape of a test that proves nothing.
    |
    | `->group()`, because `ClassSessionFactory`'s default type is Group.
    */
    $this->teacherProfile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);

    SettlementRate::factory()->group()->amount(5000)->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $this->teacherProfile->getKey(),
        'effective_from' => CarbonImmutable::now()->subMonth(),
    ]);
});

/** A funded, enrolled student with a seat on `$session`. */
function seatedStudent(ClassSession $session): User
{
    $test = test();

    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);
    fundBooking($test->workspace, $student, $test->course);

    app(BookSeat::class)->handle($session->refresh(), $student);

    return $student;
}

/**
 * A session of `$seats` seats, optionally starting somewhere other than the
 * default five minutes from now.
 *
 * ⚠️ THE START TIME IS A PARAMETER BECAUSE THE CANCELLATION DEADLINE IS DERIVED
 * FROM IT. `billableSession()`'s default puts the deadline a day in the PAST
 * (the window is 1440 minutes), which is right for every case here except the
 * pair that exists to measure that very boundary.
 */
function verdictSession(int $seats = 1, ?CarbonImmutable $startsAt = null): ClassSession
{
    $test = test();

    $session = billableSession($test->workspace, $test->owner, $test->course, seatsTotal: $seats);

    if ($startsAt !== null) {
        $session->forceFill([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(60),
        ])->save();
    }

    return $session->refresh();
}

/** Close it the way a delivered session closes, with `$present` having sat through it. */
function closeDelivered(ClassSession $session, array $present = []): ClassSession
{
    $test = test();

    $session->refresh()->forceFill(['billable_seats' => $session->bookings()->count()])->save();

    return deliverBillableSession($session->refresh(), $test->owner, $present);
}

/**
 * What the student actually PAID for this seat, in credits.
 *
 * ⛔ THE BALANCE AND THE COLUMN ARE TWO DIFFERENT QUESTIONS AND BOTH ARE ASKED.
 * `credit_verdict_at` is written by `CloseClassSession`; the money is moved by
 * `ChargeSessionSeats` READING that column — which is the seam T032 changed, and
 * the seam an implementation that ignores the verdict entirely slips through. A
 * file asserting only the column passes over a build that stamps it correctly
 * and charges everybody anyway. The two disagreeing is itself the finding.
 */
function creditsSpentOnSeat(ClassSession $session, User $student): int
{
    // ⚠️ THROUGH THE BALANCE. `credit_transactions` carries no `student_user_id`
    // — a ledger row belongs to a BALANCE, and a balance is one student in one
    // course, which is the whole reason balances are never summed across courses.
    return abs((int) CreditTransaction::query()->withoutWorkspaceScope()
        ->whereIn('credit_balance_id', CreditBalance::query()->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())->select('id'))
        ->where('source_type', 'class_session')
        ->where('source_id', $session->getKey())
        ->where('credits', '<', 0)
        ->sum('credits'));
}

/** Was this seat charged? The one column, read where the product reads it. */
function seatWasCharged(ClassSession $session, User $student): bool
{
    return Attendance::query()->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $student->getKey())
        ->whereNotNull('credit_verdict_at')
        ->exists();
}

/** What the teacher earned for THIS seat, in minor units. Zero means nothing. */
function teacherDueForSeat(ClassSession $session, User $student): int
{
    return (int) TeachingUnit::query()->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $student->getKey())
        ->sum('amount_minor');
}

/** May this student open anything of this session? */
function contentIsOpen(ClassSession $session, User $student): bool
{
    return app(SessionContentAccess::class)
        ->mayOpenSessionContent($student, (int) $session->getKey());
}

// ---------------------------------------------------------------------------
// FR-008د — the five rows, plus the ejection FR-036 adds beside them.
// ---------------------------------------------------------------------------

it('charges the student who reached the bar, pays the teacher, and opens the hour', function (): void {
    $session = verdictSession();
    $student = seatedStudent($session);

    closeDelivered($session, [$student]);

    expect(seatWasCharged($session, $student))->toBeTrue()
        ->and(creditsSpentOnSeat($session, $student))->toBe(1)
        ->and(teacherDueForSeat($session, $student))->toBe(5000)
        ->and(contentIsOpen($session, $student))->toBeTrue();
});

it('charges the silent no-show, pays the teacher, and opens the hour with no second consent', function (): void {
    /*
    | ⚠️ THE MOST IMPORTANT CASE IN THE SHIPMENT, and the one every «charge by
    | attendance» implementation gets backwards. Most sessions here are
    | one-to-one: a student who simply does not appear costs the teacher the
    | whole hour, so the seat is charged AND the content opens — they paid for
    | the hour, so they receive it (FR-008ج · SC-014).
    */
    $session = verdictSession();
    $student = seatedStudent($session);

    closeDelivered($session);

    expect(seatWasCharged($session, $student))->toBeTrue()
        ->and(creditsSpentOnSeat($session, $student))->toBe(1)
        ->and(teacherDueForSeat($session, $student))->toBe(5000)
        ->and(contentIsOpen($session, $student))->toBeTrue()
        // SC-014's one-to-one arm, spelled out: a whole unit, never a fraction
        // of an empty room, and `attended` and `charged` disagreeing is the
        // entire reason there are two columns.
        ->and($session->refresh()->attended_seats)->toBe(0)
        ->and($session->charged_seats)->toBe(1);
});

it('exempts the excuse the teacher accepted before the room closed, and keeps the content shut', function (): void {
    $session = verdictSession();
    $student = seatedStudent($session);

    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $student->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()]);

    closeDelivered($session);

    expect(seatWasCharged($session, $student))->toBeFalse()
        ->and(creditsSpentOnSeat($session, $student))->toBe(0)
        // ⚠️ AND THE TEACHER EARNS NOTHING FOR IT (FR-014). Without this the
        // platform pays for the excuse out of its own pocket — the student's
        // credit comes back and the teacher's fee does not.
        ->and(teacherDueForSeat($session, $student))->toBe(0)
        // Locked, not opened: the exemption is not a gift of the material.
        ->and(contentIsOpen($session, $student))->toBeFalse();
});

it('charges nothing to the student the teacher ejected, and opens it anyway', function (): void {
    /*
    | FR-036 — the two halves are one rule. «إخراج» must not become an indirect
    | deduction button (FR-004 forbids a teacher's act pricing anything), and the
    | student must not be punished twice: removed from the room AND locked out of
    | what the room produced.
    */
    $session = verdictSession();
    $student = seatedStudent($session);

    /*
    | ⛔ THE EJECTION IS RECORDED BEFORE THE ONE CLOSE, NEVER BY CLOSING TWICE.
    | The first draft delivered the session, stamped `removed_at`, reset the three
    | verdict columns and closed again — which fired `SessionDelivered` a second
    | time on a session CLAUDE.md says fires it exactly once, left the first
    | close's ledger entry and teaching unit standing, and made every number here
    | an artefact of the fixture rather than of the rule. `removed_at` IS
    | fillable, and `completeRegister()`'s `firstOrCreate` finds this row rather
    | than writing its own.
    */
    Attendance::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => AttendanceStatus::Absent,
        'auto_status' => AttendanceStatus::Absent,
        'removed_at' => now()->subMinutes(10),
    ]);

    closeDelivered($session);

    expect(seatWasCharged($session, $student))->toBeFalse()
        ->and(creditsSpentOnSeat($session, $student))->toBe(0)
        ->and(teacherDueForSeat($session, $student))->toBe(0)
        // ⚠️ OPEN WITHOUT CONSENT — the one exempt case whose content is not
        // shut, because the reason they missed it was the teacher's decision.
        ->and(contentIsOpen($session, $student))->toBeTrue();
});

it('charges nobody at all when the teacher never delivered it', function (): void {
    $session = verdictSession();
    $student = seatedStudent($session);

    // No room opened, no host stay: `wasDelivered()` fails its three-part test.
    app(CloseClassSession::class)->handle($session->refresh());

    expect($session->refresh()->delivered_at)->toBeNull()
        ->and(seatWasCharged($session, $student))->toBeFalse()
        ->and(creditsSpentOnSeat($session, $student))->toBe(0)
        ->and(teacherDueForSeat($session, $student))->toBe(0)
        /*
        | «لا محتوى أصلاً» — SHUT, AND NOTHING ON OFFER EITHER.
        |
        | ⚠️ THE «not judged yet» FALLBACK DOES NOT REACH HERE, and that is half
        | its predicate rather than an accident: it is `delivered_at IS NOT NULL
        | AND attended_seats IS NULL`, i.e. the deploy window in which a session
        | was already taught and the verdict column does not exist yet. A session
        | never delivered was measured, briefly, as OPEN — which would have
        | published next week's worksheet to the whole register today.
        |
        | And no price is quoted for it either: an offer would tell the student
        | the hour happened and invite them to pay for one that did not.
        */
        ->and(contentIsOpen($session, $student))->toBeFalse()
        ->and(app(SessionContentAccess::class)
            ->unlockOfferFor($student, (int) $session->getKey()))->toBeNull();
});

// ---------------------------------------------------------------------------
// SC-015 — the cancellation deadline, measured as a PAIR.
// ---------------------------------------------------------------------------

it('answers differently either side of the cancellation deadline', function (): void {
    /*
    | ⛔ THE PAIR IS THE MEASUREMENT. One case alone passes on a build with no
    | boundary in it at all: «cancelled in the window ⇒ no charge» is already
    | true today because that seat leaves the register entirely, and «cancelled
    | late ⇒ charged» is true on any build that charges everybody. Only the two
    | together say a line was drawn, and where.
    |
    | The window is 1440 minutes, so the deadline is `starts_at - 24h`. One
    | session starts a minute past that boundary and one a minute short of it.
    */
    $inWindow = verdictSession(startsAt: CarbonImmutable::now()->addHours(24)->addMinutes(5));
    $tooLate = verdictSession(startsAt: CarbonImmutable::now()->addHours(24)->subMinutes(5));

    $early = seatedStudent($inWindow);
    $late = seatedStudent($tooLate);

    app(CancelBooking::class)->handle(
        SessionBooking::query()->withoutWorkspaceScope()
            ->where('class_session_id', $inWindow->getKey())->firstOrFail(),
    );
    app(CancelBooking::class)->handle(
        SessionBooking::query()->withoutWorkspaceScope()
            ->where('class_session_id', $tooLate->getKey())->firstOrFail(),
    );

    // The two cancellations are recorded differently, which is what the rest of
    // this case hangs on — asserting the money without this asserts an accident.
    expect(SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $inWindow->getKey())->value('status'))
        ->toBe(BookingStatus::CancelledInWindow)
        ->and(SessionBooking::query()->withoutWorkspaceScope()
            ->where('class_session_id', $tooLate->getKey())->value('status'))
        ->toBe(BookingStatus::CancelledLate);

    closeDelivered($inWindow);
    closeDelivered($tooLate);

    expect(seatWasCharged($inWindow, $early))->toBeFalse()
        ->and(creditsSpentOnSeat($inWindow, $early))->toBe(0)
        ->and(teacherDueForSeat($inWindow, $early))->toBe(0)
        ->and(contentIsOpen($inWindow, $early))->toBeFalse()
        // And the other side of the same line.
        ->and(seatWasCharged($tooLate, $late))->toBeTrue()
        ->and(creditsSpentOnSeat($tooLate, $late))->toBe(1)
        ->and(teacherDueForSeat($tooLate, $late))->toBe(5000)
        ->and(contentIsOpen($tooLate, $late))->toBeTrue();
});

// ---------------------------------------------------------------------------
// FR-008هـ — a reschedule request is notice, and the bound is the DEADLINE.
// ---------------------------------------------------------------------------

it('reads an unanswered reschedule request as notice and a refusal in time as none', function (): void {
    /*
    | ⛔ THE PAIR AGAIN, AND FOR THE SAME REASON. A request left pending until the
    | deadline passed is notice: the student did everything asked of them and
    | nobody answered. A request REFUSED before the deadline is not — they still
    | had time to cancel normally, so failing to appear is on them.
    |
    | Either case alone passes against an implementation with no bound in it.
    */
    $session = verdictSession(seats: 2);
    $unanswered = seatedStudent($session);
    $refusedInTime = seatedStudent($session);

    $deadline = $session->cancellationDeadline();

    /*
    | ⚠️ THE REFUSED ROW IS CREATED AND DECIDED **FIRST**. `unique(class_session_id,
    | pending_slot)` allows exactly ONE row per session at the zero sentinel — the
    | `concept_stats.lesson_id` family — and `pending_slot` moves off that zero
    | only when the request is decided. Created in the other order, the second
    | insert collides whatever its status is meant to be.
    */
    $refused = SessionRescheduleRequest::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $refusedInTime->getKey(),
        'from_starts_at' => $session->starts_at,
        'to_starts_at' => $session->starts_at->copy()->addDay(),
        'student_reason' => 'ظرفٌ عائليّ',
    ]);

    $refused->forceFill([
        'status' => SessionRescheduleRequest::REJECTED,
        'pending_slot' => $refused->getKey(),
        // An hour BEFORE the deadline: time enough to cancel normally.
        'decided_at' => $deadline->copy()->subHour(),
    ])->save();

    SessionRescheduleRequest::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $unanswered->getKey(),
        'from_starts_at' => $session->starts_at,
        'to_starts_at' => $session->starts_at->copy()->addDay(),
        'student_reason' => 'ظرفٌ عائليّ',
    ])->forceFill(['status' => SessionRescheduleRequest::PENDING])->save();

    closeDelivered($session);

    expect(seatWasCharged($session, $unanswered))->toBeFalse()
        ->and(creditsSpentOnSeat($session, $unanswered))->toBe(0)
        ->and(teacherDueForSeat($session, $unanswered))->toBe(0)
        ->and(contentIsOpen($session, $unanswered))->toBeFalse()
        // Refused in time, then absent: charged like anybody else.
        ->and(seatWasCharged($session, $refusedInTime))->toBeTrue()
        ->and(creditsSpentOnSeat($session, $refusedInTime))->toBe(1)
        ->and(teacherDueForSeat($session, $refusedInTime))->toBe(5000)
        ->and(contentIsOpen($session, $refusedInTime))->toBeTrue();
});

// ---------------------------------------------------------------------------
// FR-012 — the record of a consent, asserted where the verdict is.
// ---------------------------------------------------------------------------

it('records who consented, when, to which session and for how much', function (): void {
    $session = verdictSession();
    $student = seatedStudent($session);

    // Notice given, so the seat is exempt and the content is shut — which is the
    // only state in which a consent is possible at all.
    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()]);

    closeDelivered($session);

    expect(contentIsOpen($session, $student))->toBeFalse();

    app(UnlockSessionContent::class)->handle($student, $session->refresh());

    $unlock = SessionUnlock::query()->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())->firstOrFail();

    expect((int) $unlock->student_user_id)->toBe((int) $student->getKey())
        ->and($unlock->consented_at)->not->toBeNull()
        ->and($unlock->credits_charged)->toBe(1)
        ->and($unlock->reason)->toBe(SessionUnlock::REASON_CONSENT)
        ->and(contentIsOpen($session, $student))->toBeTrue();

    /*
    | ⚠️ AND THE AUDIT LINE, not only the row. `activity_log` is the one surface
    | an auditor opens, and `BillingAuditSubjects` has to name this subject type
    | or the entry is filtered straight back out of the very report it is in.
    */
    $logged = Activity::query()
        ->where('description', 'billing.session.unlocked')
        ->latest('id')
        ->firstOrFail();

    expect((int) $logged->subject_id)->toBe((int) $unlock->getKey())
        ->and(BillingAuditSubjects::slugFor((string) $logged->subject_type))->toBe('session_unlock');
});
