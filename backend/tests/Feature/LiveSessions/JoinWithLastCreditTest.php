<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ A STUDENT WHO SPENT THEIR LAST CREDIT BOOKING A LESSON MUST BE ABLE TO ENTER IT.
|
| `frozenCreditRefusal` asks «can you fund ANOTHER seat»: remaining − held. Booking
| places the hold, so at the room's door `held` includes this session's own hold —
| one credit booked ⇒ remaining 1, held 1, available 0 ⇒ 403, while `/eligibility`
| answered `open: true` and the student was told to check a booking that was fine.
| Measured before the fix: one credit ⇒ 403, two ⇒ 200.
|
| ⚠️ TWO CREDITS IS THE CONTROL, and booking a SECOND session is the other one:
| a fix that deleted the check would pass the first case and fail that one.
*/

beforeEach(function (): void {
    // A delay runs immediately on `sync`: the close job would stamp
    // `room_closed_at` inside the open itself.
    Queue::fake([CloseClassSessionJob::class]);

    [$this->workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $owner);

    $teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $owner->getKey(),
    ]);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $this->newSession = fn (int $startsInMinutes): ClassSession => ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes($startsInMinutes),
        'ends_at' => CarbonImmutable::now()->addMinutes($startsInMinutes + 60),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
});

it('lets the student into the session their credit is held for', function (int $credits): void {
    fundBooking($this->workspace, $this->student, $this->course, $credits);

    $session = ($this->newSession)(5);
    app(BookSeat::class)->handle($session, $this->student);
    app(OpenBroadcastRoom::class)->handle($session);

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $this->postJson("/api/v1/class-sessions/{$session->uuid}/join")->assertOk();
})->with(['the last credit' => 1, 'a credit to spare' => 2]);

it('still refuses a second booking when every credit is held', function (): void {
    fundBooking($this->workspace, $this->student, $this->course, 1);

    app(BookSeat::class)->handle(($this->newSession)(5), $this->student);

    $next = ($this->newSession)(24 * 60);

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $response = $this->postJson("/api/v1/class-sessions/{$next->uuid}/book")->assertStatus(409);

    // The frozen-credit sentence, not some other 409 (a full session is one too).
    expect($response->json('message'))->toContain('محجوز');
});
