<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Actions\EndFocusSession;
use App\Modules\Gamification\Actions\StartFocusSession;
use App\Modules\Gamification\Enums\FocusSessionStatus;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\FocusSession;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/**
 * The focus timer (FR-038 … FR-040 · SC-022).
 */
beforeEach(function (): void {
    $this->student = User::factory()->create(['platform_role' => PlatformRole::Student]);
});

it('awards a completed session and nothing for an interrupted one', function (): void {
    $session = app(StartFocusSession::class)->handle($this->student, 25);

    // Twenty-six minutes later: finished.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(26));
    app(EndFocusSession::class)->handle($session);
    CarbonImmutable::setTestNow();

    expect($session->refresh()->status)->toBe(FocusSessionStatus::Completed)
        ->and(AwardEntry::query()->where('action_key', 'focus_session')->count())->toBe(1)
        ->and(StudentProgress::query()->where('user_id', $this->student->getKey())->sole()->xp)->toBe(5);
});

/*
 * ⚠️ THE SERVER DECIDES, FROM THE CLOCK.
 *
 * If completion were the client's word, a "120 minute" session would finish in
 * five seconds and the daily cap would be the only thing between a student and an
 * afternoon of free experience.
 */
it('refuses to call a five-second session of two hours complete', function (): void {
    $session = app(StartFocusSession::class)->handle($this->student, 120);

    app(EndFocusSession::class)->handle($session);

    expect($session->refresh()->status)->toBe(FocusSessionStatus::Interrupted)
        ->and(AwardEntry::query()->count())->toBe(0);
});

it('bounds the duration in the Action, not only in the form', function (): void {
    expect(fn () => app(StartFocusSession::class)->handle($this->student, 100_000))
        ->toThrow(DomainException::class)
        ->and(fn () => app(StartFocusSession::class)->handle($this->student, 1))
        ->toThrow(DomainException::class);
});

it('rejects an out-of-range duration at the endpoint too', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $this->postJson('/api/v1/gamification/focus', ['minutes' => 100_000])
        ->assertStatus(422)
        ->assertJsonValidationErrors('minutes');
});

/*
 * A second start closes the first: two running sessions make "is this student
 * focusing?" ambiguous and leave an orphan whose mute nothing switches off.
 */
it('closes a running session when another is started', function (): void {
    $first = app(StartFocusSession::class)->handle($this->student, 25);
    app(StartFocusSession::class)->handle($this->student, 25);

    expect($first->refresh()->status)->toBe(FocusSessionStatus::Interrupted)
        ->and(FocusSession::query()->where('status', FocusSessionStatus::Running->value)->count())->toBe(1);
});

it('pays once however many times the end is called', function (): void {
    $session = app(StartFocusSession::class)->handle($this->student, 25);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(26));
    app(EndFocusSession::class)->handle($session);
    app(EndFocusSession::class)->handle($session->refresh());
    CarbonImmutable::setTestNow();

    expect(AwardEntry::query()->where('action_key', 'focus_session')->count())->toBe(1);
});

/*
 * ⚠️ SC-022 — THE MANDATORY MESSAGE GETS THROUGH.
 *
 * And this is measured on the BELL, not on an external channel. Quiet hours only
 * ever deferred external channels, so a test that watched one would prove nothing
 * about the surface a studying student actually sees.
 */
it('hides optional notifications during a session and never the mandatory ones', function (): void {
    app(StartFocusSession::class)->handle($this->student, 60);

    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $this->student,
        type: NotificationType::BadgeAwarded,
        variables: ['student_name' => 'سلمى', 'badge_name' => 'مواظب'],
    ));

    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $this->student,
        type: NotificationType::SecurityAlert,
        variables: ['name' => 'سلمى', 'event' => 'سُجّل دخولٌ من جهازٍ جديد.'],
    ));

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $feed = $this->getJson('/api/v1/notifications')->assertOk();
    $types = collect($feed->json('data'))->pluck('type')->all();

    expect($types)->toContain(NotificationType::SecurityAlert->value)
        ->and($types)->not->toContain(NotificationType::BadgeAwarded->value)
        // The bell counts the same set: a badge that is hidden from the feed but
        // still lights the badge is the distraction the mute exists to remove.
        ->and($this->getJson('/api/v1/notifications/unread-count')->json('unread_count'))->toBe(1);
});

/*
 * ⚠️ AND NOTHING WAS LOST. The mute is a filter on the read, so when the session
 * ends everything the student missed is simply there.
 *
 * This is why the check could not live in DispatchNotification, where the design
 * first put it: the notification record is written before any channel is
 * consulted, so a check there could only DROP the message.
 */
it('shows everything again once the session ends', function (): void {
    $session = app(StartFocusSession::class)->handle($this->student, 25);

    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $this->student,
        type: NotificationType::BadgeAwarded,
        variables: ['student_name' => 'سلمى', 'badge_name' => 'مواظب'],
    ));

    Sanctum::actingAs($this->student);
    $this->asGuest();

    expect($this->getJson('/api/v1/notifications/unread-count')->json('unread_count'))->toBe(0);

    app(EndFocusSession::class)->handle($session);

    expect($this->getJson('/api/v1/notifications/unread-count')->json('unread_count'))->toBe(1);
});

it('refuses to end somebody else’s session', function (): void {
    $session = app(StartFocusSession::class)->handle($this->student, 25);

    $stranger = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs($stranger);
    $this->asGuest();

    $this->postJson("/api/v1/gamification/focus/{$session->uuid}/end")->assertNotFound();

    expect($session->refresh()->status)->toBe(FocusSessionStatus::Running);
});

/*
| ⚠️ THE SESSION NOBODY ENDED, WHICH IS THE ORDINARY ONE. A person closes the tab
| on a running timer and `EndFocusSession` never fires — so the row stays
| `running` for ever, and `muteDuringFocus()` filters the unread COUNT and the
| feed together. Found on a real database on 2026-08-24: a forty-five-minute
| session started on the 21st, and an account whose notifications had silently
| stopped three days earlier with no badge, no error and nothing naming a cause.
|
| ⚠️ AND THE CLOCK IS MOVED RATHER THAN THE ROW BACKDATED. `started_at` is written
| by the Action, and a test that writes an old value by hand would be asserting
| about a row the product cannot produce; travelling forward exercises the same
| statement a real timer runs into.
*/
it('stops muting once the planned minutes have run out, even if nobody ended it', function (): void {
    app(StartFocusSession::class)->handle($this->student, 25);

    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $this->student,
        type: NotificationType::BadgeAwarded,
        variables: ['student_name' => 'سلمى', 'badge_name' => 'مواظب'],
    ));

    Sanctum::actingAs($this->student);
    $this->asGuest();

    expect($this->getJson('/api/v1/notifications/unread-count')->json('unread_count'))->toBe(0);

    // Past the planned window, and the row is still `running` — nobody closed it.
    $this->travel(26)->minutes();

    expect(FocusSession::query()->where('user_id', $this->student->getKey())->value('status'))
        ->toBe(FocusSessionStatus::Running)
        ->and($this->getJson('/api/v1/notifications/unread-count')->json('unread_count'))->toBe(1);
});
