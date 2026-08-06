<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\OverrideAttendance;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Models\Order;
use App\Modules\Settlement\Actions\ReleasePendingUnits;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-003 · SC-004 — nothing on the student's side of the wall moves the teacher's
| ledger.
|
| The platform is the SELLER (Q1). It sells a composite service — teaching plus
| hosting plus supervision plus support plus collection — and buys teaching units
| from the teacher. So the platform carries the risk of the sale: a refund, a
| coupon, a grant, a student who never pays at all. If any of those reached this
| ledger, the teacher would be underwriting the platform's commercial decisions,
| and every discount marketing ran would be a pay cut somebody else chose.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
    ]);

    $this->session = ClassSession::factory()->individual()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(BookSeat::class)->handle($this->session->refresh(), $this->student);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();

    // Deliver, then release: the ledger line only exists once the unit is a real
    // earning, so both steps are needed before anything can be "not moved".
    app(OpenBroadcastRoom::class)->handle($this->session->refresh());
    app(RecordPresencePing::class)->handle($this->session, $this->owner);
    Attendance::query()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->owner->getKey())
        ->update(['stay_seconds' => 3000]);
    app(CloseClassSession::class)->handle($this->session->refresh());

    $this->session->refresh()->forceFill(['recording_status' => 'published'])->save();
    app(ReleasePendingUnits::class)->handle($this->session->refresh());
});

/** The teacher's balance, which is the sum of their ledger and nothing else. */
function ledgerTotal(): int
{
    return (int) LedgerEntry::query()->sum('amount_minor');
}

it('puts the unit in the ledger once and only once', function (): void {
    expect(ledgerTotal())->toBe(5000)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('does not move when the student is refunded', function (): void {
    $before = ledgerTotal();

    // Refunding on the student's side, by whatever mechanism 006/007 will use.
    // The order is cancelled outright, which is stronger than a partial refund.
    Order::query()->where('user_id', $this->student->getKey())->update(['status' => 'cancelled']);

    expect(ledgerTotal())->toBe($before)
        ->and(TeachingUnit::query()->count())->toBe(1);
});

it('does not move when the student never paid at all', function (): void {
    $before = ledgerTotal();

    // No order exists for this student and none ever will. The teacher taught the
    // hour; collecting is the platform's problem, not theirs.
    Order::query()->where('user_id', $this->student->getKey())->delete();

    expect(ledgerTotal())->toBe($before);
});

it('does not move when the attendance mark is changed afterwards', function (): void {
    $before = ledgerTotal();
    $unitBefore = TeachingUnit::query()->first()->amount_minor;

    $attendance = Attendance::query()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->firstOrFail();

    app(OverrideAttendance::class)->handle($attendance, AttendanceStatus::Present, $this->owner, 'حضر متأخراً');

    // Q7 — the unit is the SEAT. Marking someone present or absent afterwards
    // changes a teaching record, not a payment. 005 fails the build over this in
    // AttendanceHasNoFinancialEffectTest; this is the same rule asserted from the
    // other side of the wall.
    expect(ledgerTotal())->toBe($before)
        ->and(TeachingUnit::query()->first()->amount_minor)->toBe($unitBefore)
        ->and(TeachingUnit::query()->count())->toBe(1);
});
