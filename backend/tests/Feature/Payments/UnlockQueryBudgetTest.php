<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T079 — WHAT THE UNLOCK DOOR COSTS.
|
| Six conditions run in a row before a credit moves — the hour delivered, the
| verdict frozen, the seat's own verdict, an existing unlock, the content, the
| floor with the frozen credits subtracted — and nothing measured any of them.
|
| ⚠️ THE SHAPE IS FIXED, WHICH IS WHY THIS IS A CEILING AND NOT A COMPARISON. The
| budgets over LISTS carry twice their fixture because their cost must not grow
| with rows; this endpoint has no rows at all, so there is nothing to double and
| any increase is a NEW read rather than a bigger one. That is what makes a tight
| number the right one here.
|
| ⚠️ AND THE CEILING IS THE MEASURED STEADY STATE PLUS ONE, NOT A ROUND NUMBER.
| Measured 2026-09-13 over six consecutive unlocks in one process: the first
| costs more (spatie's permission cache and the `platform_settings` rows this
| path reads are filled on the way through), and from the third onwards it is
| flat. Two warm-ups, then the measurement — the same fix the presence budget
| needed when it flaked by one. When this number moves, find the query; do not
| move the line.
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

    // Enough credits for every unlock this file performs, warm-ups included.
    fundBooking($this->workspace, $this->student, $this->course, 20);
});

/**
 * One delivered hour the student holds an exempt seat in, with material to open.
 *
 * Excused before the room closed, so the seat is not charged and the content is
 * locked — which is the only state in which an unlock is possible at all
 * (FR-011 refuses a second charge on an hour already open).
 */
function unlockableHour(object $test): ClassSession
{
    $session = billableSession($test->workspace, $test->owner, $test->course, seatsTotal: 2);

    Lesson::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'course_id' => $test->course->getKey(),
        'class_session_id' => $session->getKey(),
        'type' => LessonType::Video,
        'status' => ContentStatus::Published,
    ]);

    app(BookSeat::class)->handle($session->refresh(), $test->student);

    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $test->owner->getKey()]);

    $session->refresh()->forceFill(['billable_seats' => 1])->save();

    return deliverBillableSession($session->refresh(), $test->owner, []);
}

it('opens an hour within a fixed query budget', function (): void {
    // Built before the measurement so no fixture write lands inside it.
    $hours = [];

    for ($i = 0; $i < 3; $i++) {
        $hours[] = unlockableHour($this);
    }

    Sanctum::actingAs($this->student);

    // The student's own context: they are a member of no workspace, so the
    // resolution must be null rather than the fixture's cached one.
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    $unlock = fn (ClassSession $session) => $this->postJson(
        '/api/v1/class-sessions/'.$session->uuid.'/unlock'
    )->assertOk();

    // Two warm-ups. One leaves a cached-forever settings read inside the
    // measurement and the number drifts by one between identical runs.
    $unlock($hours[0]);
    $unlock($hours[1]);

    [$count] = countingQueries(fn () => $unlock($hours[2]));

    // Measured 2026-09-13, six consecutive unlocks in one process: 28, then 27
    // five times. The ceiling is that steady state plus one.
    expect($count)->toBeLessThanOrEqual(
        28,
        "opening an hour cost {$count} queries against a ceiling of 28 — find the new read",
    );
});
