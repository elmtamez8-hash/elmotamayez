<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\OverrideAttendance;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T082 · FR-004 (amended) — A TEACHER'S MARK MOVES MONEY IN ONE DIRECTION.
|
| ⛔ AND IT IS NO LONGER «A TEST WITH NO CODE BEHIND IT». Before T031 this Action
| touched nothing but the register; it now reverses a charge, so what is measured
| here is the DIRECTION: a mark made after the verdict EXEMPTS and GIVES BACK, and
| never CHARGES. A person typing a status that creates a debt is a person deciding
| their own pay, which is exactly why the verdict is frozen from the measured stay
| and not from `status`.
|
| ⚠️ THE FIXTURE IS AN HOUR THAT WAS ACTUALLY CHARGED, and that is not a detail.
| On a seat nothing was ever charged for there is nothing to give back, nothing to
| reverse and nothing to clear — every assertion below would be true of an Action
| with no financial arm in it at all, and the file would be hollow.
|
| ⚠️ AND BOTH SIDES OF THE HOUR ARE ASSERTED. Returning the student's credit while
| leaving the teacher's unit standing means the platform pays for the excuse out
| of its own pocket, silently, one lesson at a time.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);

    $this->attender = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->excused = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    foreach ([$this->attender, $this->excused] as $student) {
        $this->createEnrollment($this->workspace, $this->course, $student);
        $this->setCurrentWorkspace($this->workspace, $this->owner);
        fundBooking($this->workspace, $student, $this->course, 3);

        app(BookSeat::class)->handle($this->session->refresh(), $student);
    }

    // One seat exempt before the room closed, one seat charged for the hour.
    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->excused->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()]);

    $this->session->refresh()->forceFill(['billable_seats' => 2])->save();
    $this->session = deliverBillableSession($this->session->refresh(), $this->owner, [$this->attender]);
});

function markRowOf(object $test, User $student): Attendance
{
    return Attendance::query()
        ->withoutWorkspaceScope()
        ->where('class_session_id', $test->session->getKey())
        ->where('student_user_id', $student->getKey())
        ->sole();
}

it('gives the credit back, and the teaching unit with it, when an excuse is accepted afterwards', function (): void {
    $row = markRowOf($this, $this->attender);

    // The premise, asserted rather than assumed: this seat really was charged.
    expect($row->credit_verdict_at)->not->toBeNull()
        ->and((int) billingBalance($this->workspace, $this->attender, $this->course)->remaining_credits)->toBe(2);

    app(OverrideAttendance::class)->handle(
        $row,
        AttendanceStatus::Excused,
        $this->owner,
        'ظرف عائلي ثبت بعد الحصة',
        hasElevatedPermission: true,
    );

    expect((int) billingBalance($this->workspace, $this->attender, $this->course)->remaining_credits)->toBe(3)
        // Cleared with the reversal, so the register and the content gate give
        // one answer: the seat is no longer charged, so the hour shuts again.
        ->and($row->refresh()->credit_verdict_at)->toBeNull();

    $units = TeachingUnit::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->attender->getKey())
        ->get();

    expect($units->where('status', TeachingUnitStatus::Reversed))->toHaveCount(1)
        ->and((int) $units->sum('amount_minor'))->toBe(0);
});

it('never creates a charge from a mark, whatever the mark says', function (): void {
    $row = markRowOf($this, $this->excused);

    expect($row->credit_verdict_at)->toBeNull();

    $before = (int) billingBalance($this->workspace, $this->excused, $this->course)->remaining_credits;

    /*
    | ⛔ THE DIRECTION, AND THE ONLY CASE THAT MEASURES IT. «Present» on an exempt
    | seat is the mark a teacher reaches for when a student tells them afterwards
    | that they were there — and if it charged, the teacher would be typing their
    | own fee onto a student who gave notice.
    */
    app(OverrideAttendance::class)->handle(
        $row,
        AttendanceStatus::Present,
        $this->owner,
        'قال إنّه كان حاضراً',
        hasElevatedPermission: true,
    );

    expect((int) billingBalance($this->workspace, $this->excused, $this->course)->remaining_credits)->toBe($before)
        ->and($row->refresh()->credit_verdict_at)->toBeNull()
        ->and($row->status)->toBe(AttendanceStatus::Present);

    // And the teacher earns nothing new either: a unit that was never accrued is
    // not created by a mark.
    expect(TeachingUnit::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->excused->getKey())
        ->sum('amount_minor'))->toBe(0);
});

it('corrects the register without moving money when the marker has no elevated permission', function (): void {
    $row = markRowOf($this, $this->attender);

    app(OverrideAttendance::class)->handle(
        $row,
        AttendanceStatus::Excused,
        $this->owner,
        'تصحيح عادي',
    );

    // An ordinary teacher may still fix what the register says inside the edit
    // window; moving a credit back is a different act with a different bar.
    expect($row->refresh()->status)->toBe(AttendanceStatus::Excused)
        ->and($row->credit_verdict_at)->not->toBeNull()
        ->and((int) billingBalance($this->workspace, $this->attender, $this->course)->remaining_credits)->toBe(2);
});
