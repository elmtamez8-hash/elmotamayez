<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Listeners\StampCourseDelivery;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/*
| FR-021ط · Q-11 — the one signal the stop-selling guard runs on.
|
| Untested, this is silent in both directions. Drop the `Event::listen` line and
| nothing fails: every actively-taught course simply stops selling once its
| creation date passes the window, sixty days after the deploy, with no error
| anywhere. Write the stamp unconditionally and a replayed old delivery walks the
| freshness BACKWARDS and stops sales over a session that already happened.
*/

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
});

/**
 * ⚠️ Named for this file, not `deliver()`: a function declared in a test file is
 * GLOBAL to every file Pest loads afterwards, and Settlement's
 * PackageCompletionTest already owns that name. The collision is a fatal error
 * that takes the whole run down, not a failing test.
 */
function stampDeliveryOn(Course $course, CarbonImmutable $endsAt): void
{
    $session = app(WorkspaceContext::class)->forWorkspace(
        $course->workspace_id,
        fn (): ClassSession => ClassSession::factory()->create([
            'workspace_id' => $course->workspace_id,
            'course_id' => $course->getKey(),
            'ends_at' => $endsAt,
        ]),
    );

    app(StampCourseDelivery::class)->handle(new SessionDelivered($session, 1));
}

it('stamps the course when a session is delivered', function (): void {
    expect($this->course->last_delivered_at)->toBeNull();

    $endsAt = CarbonImmutable::now()->subDay();

    stampDeliveryOn($this->course, $endsAt);

    expect($this->course->refresh()->last_delivered_at?->toDateTimeString())
        ->toBe($endsAt->toDateTimeString());
});

it('never walks the stamp backwards', function (): void {
    $recent = CarbonImmutable::now()->subDay();

    stampDeliveryOn($this->course, $recent);

    // Two deliveries queued together can be handled in either order, and a retry
    // can replay a month-old one long after a newer stamp landed. Written
    // unconditionally, that ages the course into "stopped delivering" over a
    // session that already happened.
    stampDeliveryOn($this->course, CarbonImmutable::now()->subMonths(3));

    expect($this->course->refresh()->last_delivered_at?->toDateTimeString())
        ->toBe($recent->toDateTimeString());
});

it('is wired to the event, not merely written', function (): void {
    // The listener works and is unreachable is the failure this catches: a
    // dropped Event::listen line stops every course selling sixty days later,
    // with nothing raised anywhere.
    expect(Event::hasListeners(SessionDelivered::class))->toBeTrue();

    $registered = array_map(
        fn (mixed $listener): string => is_string($listener) ? $listener : $listener::class,
        Event::getRawListeners()[SessionDelivered::class] ?? [],
    );

    expect($registered)->toContain(StampCourseDelivery::class);
});
