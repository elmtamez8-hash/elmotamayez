<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\ExcuseBooking;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T077 · SC-012 — MOVING THE BAR DOES NOT RE-JUDGE THE PAST.
|
| The stay bar is a `platform_settings` row an operator edits from the panel, and
| `verdict_stay_seconds` is what stops that edit reaching hours already taught.
| Three conditions, and without any one of them the file is green over a build
| that freezes nothing:
|
| (أ) THE STAY SITS BETWEEN THE TWO RATIOS. Forty per cent of the hour, with the
|     bar moving from fifty to thirty — so the SAME student is «did not attend and
|     is excused» under the old setting and «attended and is charged» under the
|     new one. At any other stay the answer is identical before and after, and the
|     assertion is about nothing.
|
| (ب) «RE-READING» IS RE-RUNNING THE PATH. A `SELECT` proves only that nothing
|     rewrote the row, which an existing guard already forbids. The event is
|     re-dispatched and the close re-entered, which is what a retried queue job,
|     a replayed event and an operator running the sweep all do.
|
| (ج) THE NEW SETTING IS PROVED LIVE FIRST. `PlatformSettings::set()` forgets its
|     cache key, but a test that does not check would be asserting the absence of
|     a change that could never have happened — the whole file passing because the
|     new number was never read at all. The control case closes a SECOND hour
|     after the change and watches it judged at the new bar.
*/

beforeEach(function (): void {
    fakeSessionTimeline();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);
});

/** A sixty-minute hour, so the two ratios are 1800 and 1080 seconds. */
function frozenBarSession(): ClassSession
{
    $test = test();

    return ClassSession::factory()->create([
        'teacher_profile_id' => $test->teacher->getKey(),
        'course_id' => $test->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);
}

/**
 * A student who booked, was excused, and looked in for forty per cent of the hour.
 *
 * The excusal is what makes the two settings disagree: below the bar they are
 * exempt and pay nothing, above it they reached it and are charged like anybody
 * who was in the room.
 */
function frozenBarSeat(ClassSession $session): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);
    fundBooking($test->workspace, $student, $test->course);

    app(BookSeat::class)->handle($session->refresh(), $student);

    $booking = SessionBooking::query()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $student->getKey())
        ->firstOrFail();

    app(ExcuseBooking::class)->handle($booking, $test->owner);

    return $student;
}

/** Delivery, with the student's stay pinned at 1440 seconds — forty per cent. */
function frozenBarClose(ClassSession $session, User $student): ClassSession
{
    $test = test();

    app(OpenBroadcastRoom::class)->handle($session->refresh());

    foreach ([[$test->owner, 3000], [$student, 1440]] as [$person, $seconds]) {
        app(RecordPresencePing::class)->handle($session, $person);

        Attendance::query()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $person->getKey())
            ->update(['stay_seconds' => $seconds]);
    }

    $session->refresh()->forceFill(['billable_seats' => 1])->save();

    app(CloseClassSession::class)->handle($session->refresh());

    // Re-read from the row. `freezeSeatVerdict()` puts the two counts back onto
    // the in-memory model for the event's sake and deliberately does not put
    // `verdict_stay_seconds` there — nothing downstream reads it.
    return $session->refresh();
}

it('keeps a frozen verdict when the stay bar moves under it', function (): void {
    $session = frozenBarSession();
    $student = frozenBarSeat($session);
    $closed = frozenBarClose($session, $student);

    // Judged at half the hour: 1440 seconds is short of 1800, and the excusal
    // means the shortfall costs nothing.
    expect((int) $closed->verdict_stay_seconds)->toBe(1800)
        ->and((int) $closed->attended_seats)->toBe(0)
        ->and((int) $closed->charged_seats)->toBe(0);

    $row = Attendance::query()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $student->getKey())
        ->sole();

    expect($row->credit_verdict_at)->toBeNull();

    $spentBefore = CreditTransaction::query()->count();

    // (ج) — the operator's edit, and the proof it took effect. Without this line
    // every assertion below could be true because the old number was still being
    // read, which is the failure the file exists to rule out.
    PlatformSettings::set('sessions.required_stay_ratio', 0.3);

    expect(app(SessionSettings::class)->requiredStaySeconds($session->refresh()))->toBe(1080);

    // (ب) — the path re-run, not the row re-read. Each of these is something that
    // really happens: a retried listener, a replayed event, a second close.
    for ($i = 0; $i < 3; $i++) {
        SessionDelivered::dispatch(
            $session->refresh(),
            1,
            [],
            $closed->charged_seats,
        );
    }

    app(CloseClassSession::class)->handle($session->refresh());

    $after = $session->refresh();

    expect((int) $after->verdict_stay_seconds)->toBe(1800)
        ->and((int) $after->attended_seats)->toBe(0)
        ->and((int) $after->charged_seats)->toBe(0)
        ->and($row->refresh()->credit_verdict_at)->toBeNull()
        ->and(CreditTransaction::query()->count())->toBe($spentBefore);
});

it('judges the next hour at the new bar, which is what makes the case above mean something', function (): void {
    PlatformSettings::set('sessions.required_stay_ratio', 0.3);

    $session = frozenBarSession();
    $student = frozenBarSeat($session);
    $closed = frozenBarClose($session, $student);

    /*
    | ⛔ THE CONTROL. The same fixture, the same forty per cent, the opposite
    | verdict — because this hour was judged AFTER the edit. A file without it
    | asserts that a number did not change, which is also true of a setting
    | nothing reads and of a bar that never moved.
    */
    expect((int) $closed->verdict_stay_seconds)->toBe(1080)
        ->and((int) $closed->attended_seats)->toBe(1)
        ->and((int) $closed->charged_seats)->toBe(1);

    $row = Attendance::query()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $student->getKey())
        ->sole();

    expect($row->credit_verdict_at)->not->toBeNull();
});
