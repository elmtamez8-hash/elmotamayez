<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelClassSession;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Events\SessionCancelled;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| ⛔ CANCELLING WAS A READ-THEN-WRITE, AND ITS NEIGHBOUR IS A CLAIM.
|
| `CancelClassSession` asked `isTerminal()` of the model it was handed and then
| saved `cancelled` unconditionally. `CloseClassSession` moves the same row with
| a conditional UPDATE from two senders — so a cancellation that loaded the
| session a moment before the close wrote `cancelled` over a session that had
| just been COMPLETED and DELIVERED, and released every seat after the lesson
| was taught and charged.
|
| The window is opened single-threaded: the model is loaded while `scheduled`,
| and the competing close is written behind its back — which is exactly what the
| other worker does inside that window. No threads, no sleeps.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create(['created_by' => $this->owner->getKey()]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->session = ClassSession::factory()->create(['teacher_profile_id' => $this->teacher->getKey()]);
    $this->booking = app(BookSeat::class)->handle($this->session, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

it('does not cancel a session that was closed and delivered inside the window', function (): void {
    Event::fake([SessionCancelled::class]);

    // The cancellation's view of the row: loaded while still scheduled.
    $stale = ClassSession::query()->findOrFail($this->session->getKey());
    expect($stale->status)->toBe(ClassSessionStatus::Scheduled);

    // The other worker wins inside the window: the session is closed and the
    // lesson was delivered.
    DB::table('class_sessions')->where('id', $this->session->getKey())->update([
        'status' => ClassSessionStatus::Completed->value,
        'delivered_at' => now(),
    ]);

    expect(fn () => app(CancelClassSession::class)->handle($stale, 'ظرف طارئ'))
        ->toThrow(DomainException::class);

    $row = ClassSession::query()->findOrFail($this->session->getKey());

    expect($row->status)->toBe(ClassSessionStatus::Completed)
        ->and($row->delivered_at)->not->toBeNull()
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->seats_taken)->toBe(1)
        // Nothing below the claim ran: the seat is still the student's.
        ->and(SessionBooking::query()->findOrFail($this->booking->getKey())->status)
        ->toBe(BookingStatus::Booked);

    Event::assertNotDispatched(SessionCancelled::class);
});

// The room is open and students are inside: a lesson in progress is ended from
// the room, never called off from the calendar.
it('refuses to cancel a live session', function (): void {
    $this->session->forceFill(['status' => ClassSessionStatus::Live, 'room_opened_at' => now()])->save();

    expect(fn () => app(CancelClassSession::class)->handle($this->session->refresh(), 'ظرف طارئ'))
        ->toThrow(DomainException::class, 'لا يمكن إلغاء حصة جارية؛ أنهِها من الغرفة.');

    expect($this->session->refresh()->status)->toBe(ClassSessionStatus::Live)
        ->and($this->booking->refresh()->status)->toBe(BookingStatus::Booked);
});

// A session that went live between the page loading and the tap is refused by
// the CLAIM, not only by the advisory check above it.
it('refuses a session that went live behind the model\'s back', function (): void {
    $stale = ClassSession::query()->findOrFail($this->session->getKey());

    DB::table('class_sessions')->where('id', $this->session->getKey())->update([
        'status' => ClassSessionStatus::Live->value,
        'room_opened_at' => now(),
    ]);

    expect(fn () => app(CancelClassSession::class)->handle($stale, 'ظرف طارئ'))
        ->toThrow(DomainException::class);

    expect($this->session->refresh()->status)->toBe(ClassSessionStatus::Live)
        ->and($this->booking->refresh()->status)->toBe(BookingStatus::Booked);
});

// `Interrupted` sits in the teacher's attendance-rate denominator and
// `Cancelled` in neither side — cancelling it afterwards would erase a no-show.
it('refuses to cancel an abandoned session', function (): void {
    $this->session->forceFill(['status' => ClassSessionStatus::Interrupted])->save();

    expect(fn () => app(CancelClassSession::class)->handle($this->session->refresh()))
        ->toThrow(DomainException::class);

    expect($this->session->refresh()->status)->toBe(ClassSessionStatus::Interrupted);
});

it('still cancels a scheduled session and returns it cancelled', function (): void {
    $cancelled = app(CancelClassSession::class)->handle($this->session->refresh(), 'ظرف طارئ');

    expect($cancelled->status)->toBe(ClassSessionStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('ظرف طارئ')
        ->and($this->session->refresh()->status)->toBe(ClassSessionStatus::Cancelled)
        ->and($this->session->seats_taken)->toBe(0)
        ->and($this->booking->refresh()->status)->toBe(BookingStatus::Released);
});
