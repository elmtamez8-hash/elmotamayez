<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\OverrideAttendance;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\Payments\Actions\ChargeSessionSeats;
use App\Modules\Settlement\Actions\AccrueTeachingUnits;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| Audit 2026-09-27 — an excuse accepted BETWEEN the close and the queued charge.
|
| `CloseClassSession` stamps `credit_verdict_at`; the charge and the teacher's
| pay follow on a queue and both read that column. An excuse landing in between
| found no charge to reverse, left the verdict stamped — and the charge that ran
| a minute later billed the excused seat anyway, and the teacher was paid for it.
|
| `SessionDelivered` is faked HERE, and only here, because the whole point is to
| stand in the gap before its two listeners run; they are then run by hand, in
| the order a worker would.
*/
beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    Event::fake([SessionDelivered::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 3);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    fundBooking($this->workspace, $this->student, $this->course, 3);

    app(BookSeat::class)->handle($this->session->refresh(), $this->student);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
    $this->session = deliverBillableSession($this->session->refresh(), $this->owner, [$this->student]);
});

it('does not charge, or pay the teacher for, a seat excused before the charge ran', function (): void {
    $row = Attendance::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->sole();

    // The premise: judged chargeable at the close, and not charged yet.
    expect($row->credit_verdict_at)->not->toBeNull()
        ->and($this->session->refresh()->charged_at)->toBeNull();

    app(OverrideAttendance::class)->handle($row, AttendanceStatus::Excused, $this->owner, 'عذر مقبول', hasElevatedPermission: true);

    expect($row->refresh()->credit_verdict_at)->toBeNull();

    // Now the queued listeners land, as a worker would run them.
    app(ChargeSessionSeats::class)->handle($this->session->refresh(), (int) $this->session->billable_seats);
    app(AccrueTeachingUnits::class)->handle($this->session->refresh(), (int) $this->session->billable_seats);

    expect((int) billingBalance($this->workspace, $this->student, $this->course)->remaining_credits)->toBe(3)
        ->and((int) billingBalance($this->workspace, $this->student, $this->course)->held_credits)->toBe(0)
        ->and((int) TeachingUnit::query()->withoutWorkspaceScope()
            ->where('class_session_id', $this->session->getKey())
            ->where('student_user_id', $this->student->getKey())
            ->sum('amount_minor'))->toBe(0);
});
