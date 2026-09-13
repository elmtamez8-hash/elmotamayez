<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\UnlockRule;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\SessionContentAccess;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T083 — ONE WORD, TWO MEANINGS, TWO COLUMNS.
|
| «العذر» is written in two places in this product and they are not the same fact:
|
|  · `session_bookings.excused_at` — THE MONEY. The teacher accepted the excuse
|    before the room closed, so the seat is not charged and its hour stays locked
|    until the student consents to pay for it (FR-006 · FR-013).
|  · `attendances.status = Excused` — THE PASTORAL MARK. The teacher's judgement
|    that the absence is not held against the student, which is why it counts as
|    attendance wherever attendance is a condition, and why only `absent` fails.
|
| ⛔ THE THIRD CASE IS THE ONE THAT MATTERS, and it is the crossing: the pastoral
| mark on its own EXEMPTS NOTHING. A student marked `Excused` in the register whose
| booking carries no `excused_at` is charged exactly like anybody else — which is
| what stops a teacher's mark deciding their own pay (FR-004).
|
| ⚠️ THE FIRST WELL-MEANING PROGRAMMER TO UNIFY THESE BREAKS ONE OF THE TWO DOORS,
| and neither failure announces itself: collapse them towards the mark and a
| teacher's keyboard moves money; collapse them towards the column and an excused
| student is barred from the next lesson they were excused from missing.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    fundBooking($this->workspace, $this->student, $this->course, 5);
});

/** The one profile this workspace's owner has: `unique(user_id)` allows no second. */
function ownersTeacherProfile(object $test): TeacherProfile
{
    return TeacherProfile::query()->withoutWorkspaceScope()
        ->where('user_id', $test->owner->getKey())
        ->first()
        ?? TeacherProfile::factory()->create([
            'workspace_id' => $test->workspace->getKey(),
            'user_id' => $test->owner->getKey(),
        ]);
}

/** A delivered hour the student booked, sat out, and was — or was not — excused. */
function excusedHour(object $test, bool $onTheBooking): ClassSession
{
    $session = billableSession($test->workspace, $test->owner, $test->course, seatsTotal: 5);

    app(BookSeat::class)->handle($session->refresh(), $test->student);

    if ($onTheBooking) {
        SessionBooking::query()->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $test->student->getKey())
            ->update(['excused_at' => now(), 'excused_by_user_id' => $test->owner->getKey()]);
    }

    $session->refresh()->forceFill(['billable_seats' => 1])->save();

    // Nobody in the room: the student missed the hour either way, so the excusal
    // is the only difference between the two cases.
    return deliverBillableSession($session->refresh(), $test->owner, []);
}

it('charges nothing and keeps the hour shut when the excuse is on the booking', function (): void {
    $session = excusedHour($this, onTheBooking: true);

    expect((int) billingBalance($this->workspace, $this->student, $this->course)->remaining_credits)->toBe(5)
        ->and((int) billingBalance($this->workspace, $this->student, $this->course)->held_credits)->toBe(0)
        // Locked, and that is the trade rather than an oversight: nothing was
        // paid, so nothing was bought. The road out is the offer.
        ->and(app(SessionContentAccess::class)->mayOpenSessionContent($this->student, (int) $session->getKey()))->toBeFalse();
});

it('charges the seat anyway when the excuse is only a mark in the register', function (): void {
    $session = excusedHour($this, onTheBooking: false);

    // ⛔ THE CROSSING. The teacher may still mark the row `Excused` afterwards —
    // pastorally it is the right mark — and it moves nothing, because the money
    // question was settled by a column this mark does not touch.
    app(WorkspaceContext::class)->forWorkspace($this->workspace, function () use ($session): void {
        Attendance::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $this->student->getKey())
            ->update(['status' => AttendanceStatus::Excused->value]);
    });

    expect((int) billingBalance($this->workspace, $this->student, $this->course)->remaining_credits)->toBe(4)
        ->and(app(SessionContentAccess::class)->mayOpenSessionContent($this->student, (int) $session->getKey()))->toBeTrue();
});

it('lets the pastoral mark carry the student into the next lesson, where an absence would not', function (): void {
    UnlockRule::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'requires_attendance' => true,
        'requires_assignment' => false,
        'min_score_pct' => 0,
    ]);

    $profile = ownersTeacherProfile($this);

    $make = fn (ClassSessionStatus $status, string $when): ClassSession => ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $profile->getKey(),
        'course_id' => $this->course->getKey(),
        'status' => $status,
        'starts_at' => now()->parse($when),
        'ends_at' => now()->parse($when)->addHour(),
        'seats_total' => 10,
        'seats_taken' => 0,
    ]);

    $last = $make(ClassSessionStatus::Completed, '-1 week');
    $next = $make(ClassSessionStatus::Scheduled, '+3 days');

    attendanceRow($this->workspace, $last, $this->student, AttendanceStatus::Excused);

    Sanctum::actingAs($this->student);
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    $this->postJson("/api/v1/class-sessions/{$next->uuid}/book")->assertCreated();
});

it('refuses the same booking when the previous lesson was a plain absence', function (): void {
    // The control. Without it the case above passes against a build with no
    // attendance condition in the gate at all.
    UnlockRule::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'requires_attendance' => true,
        'requires_assignment' => false,
        'min_score_pct' => 0,
    ]);

    $profile = ownersTeacherProfile($this);

    $make = fn (ClassSessionStatus $status, string $when): ClassSession => ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $profile->getKey(),
        'course_id' => $this->course->getKey(),
        'status' => $status,
        'starts_at' => now()->parse($when),
        'ends_at' => now()->parse($when)->addHour(),
        'seats_total' => 10,
        'seats_taken' => 0,
    ]);

    $last = $make(ClassSessionStatus::Completed, '-1 week');
    $next = $make(ClassSessionStatus::Scheduled, '+3 days');

    attendanceRow($this->workspace, $last, $this->student, AttendanceStatus::Absent);

    Sanctum::actingAs($this->student);
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    $refused = $this->postJson("/api/v1/class-sessions/{$next->uuid}/book");

    $refused->assertStatus(409);
    expect($refused->json('message'))->toContain('حضور');
});
