<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| ⛔ THIS FILE WAS INVERTED ON 2026-09-13, NOT DELETED — ٠٣٥ · FR-002 · T017.
|
| It used to assert «the seat is charged, never the attendance», and it was
| right: ٠١٤ · FR-025د decided that in as many words, and `ChargeSessionSeats`
| carried the comment «ATTENDANCE IS NOT CONSULTED, ANYWHERE». The argument
| behind that decision was that the absentee still RECEIVES the hour — the
| recording, the files and the homework all reach them — so charging them is
| charging for what they got.
|
| ٠٣٥ · FR-003 removes that premise: nothing of a session opens for somebody who
| did not sit in it unless they consent to spend the credit. The moment the
| absentee stops receiving the hour, charging them for it stops being the same
| transaction. The two decisions ship together or neither ships.
|
| ⚠️ WHAT SURVIVES IS THE HALF THIS FILE WAS ALWAYS REALLY ABOUT: the TEACHER'S
| MARK moves no money (FR-004). The stay is measured by a heartbeat against our
| own route and the arithmetic is the server's; `status` is a pastoral judgement
| a human types, and a human typing a number that decides their own pay is the
| defect `OverrideAttendance`'s window exists inside.
|
| ⚠️ AND «EXCUSED» IS NOW TWO DIFFERENT FACTS ON TWO DIFFERENT COLUMNS. The
| FINANCIAL excuse is `session_bookings.excused_at`, written before the room
| closes, and it exempts. The EDUCATIONAL one is `attendances.status = excused`,
| and it exempts nothing — it says the absence is not held against the student
| when the next booking's eligibility is judged. One word, two meanings; the
| first developer to unify them in good faith breaks one of the two doors.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 4);

    $this->students = collect(range(1, 4))->map(function (): User {
        $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
        $this->createEnrollment($this->workspace, $this->course, $student);
        $this->setCurrentWorkspace($this->workspace, $this->owner);
        // Prepaid is the default, so a seat has to be paid for before it can be
        // taken. The subject of this file is not funding; the funding is fixture.
        fundBooking($this->workspace, $student, $this->course);

        app(BookSeat::class)->handle($this->session->refresh(), $student);

        return $student;
    });

    $this->session->refresh()->forceFill(['billable_seats' => 4])->save();
});

/**
 * Marks and stays in the register, in the order the four students were created.
 *
 * ⚠️ THE STAY IS `forceFill`ED AND NOT MASS-ASSIGNED. `stay_seconds` left
 * `Attendance::$fillable` with ٠٣٥ — it became money — so passing it in an
 * `updateOrCreate` attributes array is DISCARDED IN SILENCE and every row here
 * would land on the column default of zero, turning this whole file into four
 * silent no-shows and every number in it into an accident.
 *
 * @param  array<int, array{0: AttendanceStatus, 1: int}>  $rows  status and stay per student
 */
function markRegisterWith(array $rows): void
{
    $test = test();

    foreach ($test->students as $index => $student) {
        [$status, $staySeconds] = $rows[$index];

        $attendance = Attendance::query()->updateOrCreate(
            [
                'class_session_id' => $test->session->getKey(),
                'student_user_id' => $student->getKey(),
            ],
            [
                'workspace_id' => $test->workspace->getKey(),
                'status' => $status,
                'auto_status' => $status,
            ],
        );

        $attendance->forceFill(['stay_seconds' => $staySeconds])->save();
    }
}

/** How many consumption entries actually moved a credit. */
function chargedSeatCount(): int
{
    return CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)
        ->where('credits', '<', 0)
        ->count();
}

/** Close it the way a delivered session closes, with the register already written. */
function closeWithRegister(): void
{
    $test = test();

    // The host's own row is the evidence `wasDelivered()` reads — it exists ON
    // PURPOSE and is not a student row. Written here rather than through
    // `deliverBillableSession()` because that helper rebuilds the register.
    $host = Attendance::query()->updateOrCreate(
        [
            'class_session_id' => $test->session->getKey(),
            'student_user_id' => $test->owner->getKey(),
        ],
        [
            'workspace_id' => $test->workspace->getKey(),
            'status' => AttendanceStatus::Present,
            'auto_status' => AttendanceStatus::Present,
        ],
    );

    $host->forceFill(['stay_seconds' => 3000, 'first_joined_at' => now()->subHour()])->save();

    app(CloseClassSession::class)->handle($test->session->refresh()->forceFill([
        'room_opened_at' => now()->subHour(),
    ]));
}

it('charges by the stay and never by the mark the teacher wrote', function (): void {
    /*
     | The bar is half of a sixty-minute session, i.e. 1800 seconds. The marks
     | are deliberately at odds with the stays: two people who sat through it are
     | marked absent, and two who never appeared are marked present.
     |
     | ⚠️ THIS IS WHAT FAILS ON A BUILD THAT READS `status`. Wire the charge to
     | the mark and the first two are exempted and the count drops to two —
     | which is also exactly the shape of a teacher deciding their own pay.
     */
    markRegisterWith([
        [AttendanceStatus::Absent, 2400],
        [AttendanceStatus::Absent, 2400],
        [AttendanceStatus::Present, 0],
        [AttendanceStatus::Present, 0],
    ]);

    closeWithRegister();

    // All four: the first two reached the bar, and the second two are silent
    // no-shows — who are charged, because they gave nobody any notice and the
    // seat was closed to everyone else for the whole hour (FR-008د · row 3).
    expect(chargedSeatCount())->toBe(4);
});

it('exempts the FINANCIAL excuse and not the educational one', function (): void {
    markRegisterWith([
        // Marked excused by the teacher, and present for the whole hour: the
        // pastoral mark says nothing about money in either direction.
        [AttendanceStatus::Excused, 2400],
        // Marked excused, never appeared, and NO excuse on the booking: a silent
        // no-show wearing a kind word.
        [AttendanceStatus::Excused, 0],
        [AttendanceStatus::Absent, 0],
        [AttendanceStatus::Absent, 0],
    ]);

    // The fourth student's teacher accepted their excuse BEFORE the room closed
    // — the one door FR-008د's fourth row travels through.
    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->students[3]->getKey())
        ->update(['excused_at' => now()->subHour(), 'excused_by_user_id' => $this->owner->getKey()]);

    closeWithRegister();

    // Three, not four: the excused booking alone is exempt. On a build with no
    // feature this is four, and on a build that read the MARK instead it is two.
    expect(chargedSeatCount())->toBe(3);
});

it('charges nobody when the teacher never delivered it, however present they were', function (): void {
    /*
     | ⚠️ STILL TRUE AFTER ٠٣٥, AND NOT TOUCHED. Delivery is the premise of the
     | charge (FR-025أ) and ٠٣٥ narrows who is charged WITHIN a delivered
     | session — it does not create a charge where there was none.
     */
    markRegisterWith([
        [AttendanceStatus::Present, 3000],
        [AttendanceStatus::Present, 3000],
        [AttendanceStatus::Present, 3000],
        [AttendanceStatus::Present, 3000],
    ]);

    // The room is never opened and the teacher never joins, so
    // CloseClassSession's three-part test fails and SessionDelivered is not
    // fired at all.
    app(CloseClassSession::class)->handle($this->session->refresh());

    expect($this->session->refresh()->delivered_at)->toBeNull()
        ->and(chargedSeatCount())->toBe(0);
});
