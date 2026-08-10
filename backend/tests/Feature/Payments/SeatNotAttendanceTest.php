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
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-008 · FR-025د — the seat is charged, never the attendance.
|
| Four students, four different marks, four entries. What the student bought is
| the SESSION PACKAGE — the recording, the files, the homework — and all four of
| them received it. `Excused` is the one that reads as a refund and is not: its
| meaning is pastoral and reportorial, and FR-025ب says so in as many words.
|
| The mirror image is asserted too, because it is the failure that costs money in
| the other direction: a session the teacher never delivered charges NOBODY, no
| matter how present everyone was.
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
        // taken. The subject of this file is not money; the funding is fixture.
        fundBooking($this->workspace, $student, $this->course);

        app(BookSeat::class)->handle($this->session->refresh(), $student);

        return $student;
    });

    $this->session->refresh()->forceFill(['billable_seats' => 4])->save();
});

/** Marks in the register, in the order the four students were created. */
function markRegisterWith(AttendanceStatus ...$statuses): void
{
    $test = test();

    foreach ($test->students as $index => $student) {
        Attendance::query()->updateOrCreate(
            [
                'class_session_id' => $test->session->getKey(),
                'student_user_id' => $student->getKey(),
            ],
            [
                'workspace_id' => $test->workspace->getKey(),
                'status' => $statuses[$index],
                'auto_status' => $statuses[$index],
                'stay_seconds' => $statuses[$index] === AttendanceStatus::Absent ? 0 : 2400,
            ],
        );
    }
}

it('charges all four seats whatever the register says', function (): void {
    markRegisterWith(
        AttendanceStatus::Present,
        AttendanceStatus::Late,
        AttendanceStatus::Absent,
        AttendanceStatus::Excused,
    );

    deliverBillableSession($this->session, $this->owner);

    $entries = CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->get();

    expect($entries)->toHaveCount(4)
        // One per student, not four on one balance — the balance is per
        // (student, course), and four entries on one of them would be the same
        // count with the wrong people paying.
        ->and($entries->pluck('credit_balance_id')->unique())->toHaveCount(4)
        ->and($entries->pluck('credits')->unique()->all())->toBe([-1]);
});

it('does not exempt the excused seat', function (): void {
    // Named on its own because it is the one a reader expects to be refunded,
    // and the one a well-meaning change would exempt first.
    markRegisterWith(
        AttendanceStatus::Excused,
        AttendanceStatus::Excused,
        AttendanceStatus::Excused,
        AttendanceStatus::Excused,
    );

    deliverBillableSession($this->session, $this->owner);

    expect(CreditTransaction::query()->withoutWorkspaceScope()
        ->where('type', CreditTransactionType::Consume)->count())->toBe(4);
});

it('charges nobody when the teacher never delivered it, however present they were', function (): void {
    markRegisterWith(
        AttendanceStatus::Present,
        AttendanceStatus::Present,
        AttendanceStatus::Present,
        AttendanceStatus::Present,
    );

    // The room is never opened and the teacher never joins, so
    // CloseClassSession's three-part test fails and SessionDelivered is not
    // fired at all (FR-025أ).
    app(CloseClassSession::class)->handle($this->session->refresh());

    expect($this->session->refresh()->delivered_at)->toBeNull()
        ->and(CreditTransaction::query()->withoutWorkspaceScope()
            ->where('type', CreditTransactionType::Consume)->count())->toBe(0);
});
