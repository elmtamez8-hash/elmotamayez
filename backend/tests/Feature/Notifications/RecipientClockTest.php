<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\UserClock;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| Every time the SERVER prints is on the RECIPIENT's own clock, with the clock
| named (owner decision 2026-09-25).
|
| The product has users in Qatar (UTC+3 all year) and in Egypt (UTC+3 until
| 2026-10-29, UTC+2 after). A message that prints one bare «18:00» is true for
| only one of them — so a Cairo teacher and a Doha student each read the same
| lesson on their own clock, and the label says which clock that is.
|
| ⚠️ NOVEMBER, ON PURPOSE. In summer the two clocks agree, and a test run then
| would pass against a build that still printed the platform's zone.
*/

beforeEach(function (): void {
    fakeSessionTimeline();
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00:00', 'UTC'));
});

it('reads one lesson on two clocks — Cairo and Doha — a winter hour apart', function (): void {
    $cairo = (new User)->forceFill(['timezone' => 'Africa/Cairo']);
    $doha = (new User)->forceFill(['timezone' => 'Asia/Qatar']);
    $at = CarbonImmutable::parse('2026-11-18 15:00', 'UTC');

    expect(UserClock::format($cairo, $at))->toBe('2026-11-18 17:00 (توقيت مصر)')
        ->and(UserClock::format($doha, $at))->toBe('2026-11-18 18:00 (توقيت قطر)');
});

it('falls back to the platform zone for an account with none — or with a name PHP does not know', function (): void {
    $at = CarbonImmutable::parse('2026-11-18 15:00', 'UTC');

    expect(UserClock::format(new User, $at))->toBe('2026-11-18 18:00 (توقيت قطر)')
        ->and(UserClock::format((new User)->forceFill(['timezone' => 'Mars/Olympus']), $at))->toBe('2026-11-18 18:00 (توقيت قطر)')
        ->and(UserClock::format(null, $at))->toBe('2026-11-18 18:00 (توقيت قطر)');
});

it('tells a Cairo teacher the asked hour on BOTH clocks, and a Doha student the answer on both too', function (): void {
    $fx = privateSessionFixture();
    $fx['owner']->forceFill(['timezone' => 'Africa/Cairo'])->save();
    $fx['student']->forceFill(['timezone' => 'Asia/Qatar'])->save();

    Sanctum::actingAs($fx['student']);
    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    $cairoClock = $fx['startsAt']->setTimezone('Africa/Cairo')->format('Y-m-d H:i');
    $dohaClock = $fx['startsAt']->setTimezone('Asia/Qatar')->format('Y-m-d H:i');

    // The fixture's lesson is in November: the two clocks differ by an hour.
    expect($cairoClock)->not->toBe($dohaClock);

    $asked = assertNotifiedOnce($fx['owner'], NotificationType::PrivateSessionRequested);

    // Owner decision 2026-09-26: the two clocks differ at that instant, so the
    // teacher reads their own hour AND the student's.
    $dohaHour = $fx['startsAt']->setTimezone('Asia/Qatar')->format('H:i');
    $cairoHour = $fx['startsAt']->setTimezone('Africa/Cairo')->format('H:i');

    expect((string) $asked->body)->toContain($cairoClock.' بتوقيتك · '.$dohaHour.' بتوقيت الطالب');

    $request = PrivateSessionRequest::query()->withoutWorkspaceScope()->pending()->sole();
    $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);
    Sanctum::actingAs($fx['owner']);
    $this->postJson("/api/v1/manage/private-session-requests/{$request->uuid}/decide", ['accept' => true])->assertOk();

    $answer = assertNotifiedOnce($fx['student'], NotificationType::PrivateSessionAccepted);

    expect((string) $answer->body)->toContain($dohaClock.' بتوقيتك · '.$cairoHour.' بتوقيت المدرّس');
});

describe('PUT /me/timezone', function (): void {
    it('stamps the browser\'s zone on an account that has none, and shows it on /auth/me', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Africa/Cairo', 'only_if_unset' => true])
            ->assertOk()
            ->assertJsonPath('timezone', 'Africa/Cairo');

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('timezone', 'Africa/Cairo');
    });

    it('lets the browser stamp follow the browser until the person chooses', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Asia/Qatar', 'source' => 'browser'])->assertOk();
        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Africa/Cairo', 'source' => 'browser'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Africa/Cairo')
            ->assertJsonPath('timezone_source', 'browser');
    });

    it('never lets the stamp — or the quiet-hours form — overwrite a zone the person CHOSE', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // An explicit choice (the settings card sends no source, or `manual`).
        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Africa/Cairo'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Africa/Cairo')
            ->assertJsonPath('timezone_source', 'manual');

        // The sign-in stamp, in both spellings, from a laptop on another zone.
        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Asia/Qatar', 'source' => 'browser'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Africa/Cairo');
        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Asia/Qatar', 'only_if_unset' => true])
            ->assertOk()
            ->assertJsonPath('timezone', 'Africa/Cairo');

        // The quiet-hours form sends the browser's zone too.
        $this->putJson('/api/v1/notifications/quiet-hours', [
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
            'timezone' => 'Asia/Qatar',
        ])->assertOk();

        expect($user->fresh()?->timezone)->toBe('Africa/Cairo')
            ->and($user->fresh()?->timezone_source)->toBe('manual')
            ->and($user->fresh()?->quiet_hours_start)->not->toBeNull();

        // A new explicit choice does move it.
        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Asia/Qatar', 'source' => 'manual'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Asia/Qatar');
    });

    it('lets the quiet-hours form report the browser zone while nobody chose one', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/quiet-hours', [
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
            'timezone' => 'Africa/Cairo',
        ])->assertOk();

        expect($user->fresh()?->timezone)->toBe('Africa/Cairo')
            ->and($user->fresh()?->timezone_source)->toBe('browser');
    });

    it('refuses a name that is not a zone', function (): void {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Mars/Olympus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    });
});

describe('both clocks', function (): void {
    it('prints the other party\'s hour only when the two clocks differ at that instant', function (): void {
        $cairo = (new User)->forceFill(['timezone' => 'Africa/Cairo']);
        $doha = (new User)->forceFill(['timezone' => 'Asia/Qatar']);

        // November: 17:00 Cairo, 18:00 Doha.
        expect(UserClock::formatBoth($cairo, $doha, CarbonImmutable::parse('2026-11-18 15:00', 'UTC'), 'المدرّس'))
            ->toBe('2026-11-18 17:00 بتوقيتك · 18:00 بتوقيت المدرّس')
            // October: both UTC+3 — two names, one hour, so one time, zone named.
            ->and(UserClock::formatBoth($cairo, $doha, CarbonImmutable::parse('2026-10-20 14:00', 'UTC'), 'المدرّس'))
            ->toBe('2026-10-20 17:00 (توقيت مصر)')
            // The other side of midnight carries its own date.
            ->and(UserClock::formatBoth($cairo, $doha, CarbonImmutable::parse('2026-11-18 21:30', 'UTC'), 'المدرّس'))
            ->toBe('2026-11-18 23:30 بتوقيتك · 2026-11-19 00:30 بتوقيت المدرّس');
    });

    it('sends each side the other\'s zone on the private-request lists', function (): void {
        $fx = privateSessionFixture();
        $fx['owner']->forceFill(['timezone' => 'Africa/Cairo'])->save();
        $fx['student']->forceFill(['timezone' => 'Asia/Qatar'])->save();

        Sanctum::actingAs($fx['student']);
        $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
            'starts_at' => $fx['startsAt']->toIso8601String(),
        ])->assertCreated();

        $this->getJson('/api/v1/private-session-requests')
            ->assertOk()
            ->assertJsonPath('data.0.counterpart_timezone', 'Africa/Cairo');

        $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);
        Sanctum::actingAs($fx['owner']);

        $this->getJson('/api/v1/manage/private-session-requests')
            ->assertOk()
            ->assertJsonPath('data.0.counterpart_timezone', 'Asia/Qatar');
    });
});
