<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Jobs\RunRetentionSweepJob;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Compliance\Models\RetentionSweepRun;
use App\Modules\Identity\Http\Resources\AuthSessionResource;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Support\AuthSessionRetention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Spec 038 · US1 — the age arms, and the five landmines under them.
 *
 * ⛔ EVERY ROW IS AGED BY QUERY, NEVER INSIDE `create()`. `created_at` is not in
 * `$fillable`, so a "year-old" row written in the attribute array is born today —
 * and every retention assertion above it is then green against data too young to
 * sweep, which is the opposite of what it claims.
 *
 * ⛔ AND THE DEVICE ARM GETS FIVE CASES, ONE PER GUARD. It carries four conditions
 * and the obvious fixture satisfies two at once, so every single-condition
 * deletion survives it — the shape spec 006's US6 shipped nine times over, where
 * removing the whole guard left every case passing.
 */
function ageRetentionRow(AuthSession|Device $row, string $column, int $daysAgo): void
{
    DB::table($row->getTable())
        ->where('id', $row->getKey())
        ->update([$column => now()->subDays($daysAgo)]);
}

/** A device and a session for one person, both as old as asked. */
function agedRetentionPair(User $user, int $deviceAgeDays, int $sessionEndedDaysAgo): array
{
    $device = Device::factory()->create(['user_id' => $user->getKey()]);
    ageRetentionRow($device, 'created_at', $deviceAgeDays);

    $session = AuthSession::factory()->ended()->create([
        'user_id' => $user->getKey(),
        'device_id' => $device->getKey(),
    ]);
    ageRetentionRow($session, 'created_at', $deviceAgeDays);
    ageRetentionRow($session, 'ended_at', $sessionEndedDaysAgo);

    return [$device->fresh(), $session->fresh()];
}

function retentionSweepRun(): RetentionSweepRun
{
    RunRetentionSweepJob::dispatchSync();

    return RetentionSweepRun::query()->latest('id')->firstOrFail();
}

beforeEach(function (): void {
    $this->subject = User::factory()->create();
});

it('clears the address, the panel handle and the device pointer of an aged ended session', function (): void {
    [$device, $session] = agedRetentionPair($this->subject, 400, 300);

    // A positive control in the SAME run: nothing here may touch a recent row.
    [, $recent] = agedRetentionPair($this->subject, 400, 3);

    $run = retentionSweepRun();

    $session->refresh();
    $recent->refresh();

    expect($session->ip_hash)->toBeNull()
        // FR-015 — the column added 2026-09-17 and missing from every inventory
        // written for this table. Dropping it from the arm survives every other
        // assertion in this file, so it is asserted in the same breath.
        ->and($session->session_id)->toBeNull()
        ->and($session->device_id)->not->toBe($device->getKey())
        ->and($run->rows_anonymised)->toBeGreaterThan(0);

    expect($recent->ip_hash)->not->toBeNull()
        ->and($recent->device_id)->toBe($recent->device_id);
});

it('re-points the session at one fingerprint-less device per user', function (): void {
    [, $first] = agedRetentionPair($this->subject, 400, 300);
    [, $second] = agedRetentionPair($this->subject, 400, 280);

    retentionSweepRun();

    $tombstones = Device::query()
        ->where('user_id', $this->subject->getKey())
        ->where('fingerprint_hash', 'like', AuthSessionRetention::ANONYMISED_PREFIX.'u%')
        ->get();

    expect($tombstones)->toHaveCount(1)
        ->and($tombstones->first()->label)->toBe(AuthSessionRetention::TOMBSTONE_LABEL);

    // ⛔ THIS IS WHAT PROVES THE ANONYMISATION ANONYMISED. Without the redirect,
    // both rows still point at a device carrying a full fingerprint, and the
    // session's location is recoverable from the nearest unswept sibling.
    expect($first->fresh()->device_id)->toBe($tombstones->first()->getKey())
        ->and($second->fresh()->device_id)->toBe($tombstones->first()->getKey());

    foreach ([$first, $second] as $session) {
        expect($session->fresh()->device->fingerprint_hash)
            ->toStartWith(AuthSessionRetention::ANONYMISED_PREFIX);
    }
});

it('leaves an aged ACTIVE session completely alone', function (): void {
    $device = Device::factory()->create(['user_id' => $this->subject->getKey()]);
    ageRetentionRow($device, 'created_at', 400);

    $active = AuthSession::factory()->create([
        'user_id' => $this->subject->getKey(),
        'device_id' => $device->getKey(),
    ]);
    ageRetentionRow($active, 'created_at', 400);

    $before = AuthSession::query()->where('status', AuthSession::STATUS_ACTIVE)->count();

    retentionSweepRun();

    $active->refresh();

    // ⚠️ AGED, not young. A young active row is spared by ANY wrong build too —
    // `NULL < :before` is NULL — so only this fixture bites an arm that filters on
    // `created_at` and forgets `status`.
    expect($active->ip_hash)->not->toBeNull()
        ->and($active->device_id)->toBe($device->getKey())
        // SC-003, asserted platform-wide rather than over this fixture's account.
        ->and(AuthSession::query()->where('status', AuthSession::STATUS_ACTIVE)->count())->toBe($before);
});

it('keeps the sign-in door readable on an anonymised panel row', function (): void {
    $device = Device::factory()->create(['user_id' => $this->subject->getKey()]);
    ageRetentionRow($device, 'created_at', 400);

    $panel = AuthSession::factory()->ended()->create([
        'user_id' => $this->subject->getKey(),
        'device_id' => $device->getKey(),
        // A panel sign-in: no token, a session handle instead.
        'token_id' => null,
        'session_id' => Str::random(40),
    ]);
    ageRetentionRow($panel, 'created_at', 400);
    ageRetentionRow($panel, 'ended_at', 300);

    retentionSweepRun();

    $payload = (new AuthSessionResource($panel->fresh()))->toArray(Request::create('/'));

    // ⛔ THE ARM CLEARS `session_id`, AND `surface` USED TO BE DERIVED FROM IT —
    // so every anonymised panel sign-in read «app», on the screen and in the
    // subject's own archive, while the spec promises the door survives.
    expect($payload['surface'])->toBe('panel')
        ->and($panel->fresh()->session_id)->toBeNull();
});

it('never deletes a device row, and leaves no session pointing at nothing', function (): void {
    agedRetentionPair($this->subject, 400, 300);

    $before = Device::query()->count();

    retentionSweepRun();

    expect(Device::query()->count())->toBeGreaterThanOrEqual($before);

    // A broken pointer is a 500 for the WHOLE request, not one row: `AuthSession`
    // documents `device_id` as always resolving, and `$this->device->label` is
    // read for every row of the screen.
    $orphans = AuthSession::query()
        ->whereNotIn('device_id', Device::query()->select('id'))
        ->count();

    expect($orphans)->toBe(0);
});

describe('the device arm, one guard per case', function (): void {
    it('spares a device that still has an active session', function (): void {
        $device = Device::factory()->create(['user_id' => $this->subject->getKey()]);
        ageRetentionRow($device, 'created_at', 400);

        $active = AuthSession::factory()->create([
            'user_id' => $this->subject->getKey(),
            'device_id' => $device->getKey(),
        ]);
        ageRetentionRow($active, 'created_at', 400);

        retentionSweepRun();

        expect($device->fresh()->fingerprint_hash)
            ->not->toStartWith(AuthSessionRetention::ANONYMISED_PREFIX);
    });

    it('spares a device whose newest session ended recently', function (): void {
        [$device] = agedRetentionPair($this->subject, 400, 3);

        retentionSweepRun();

        expect($device->fresh()->fingerprint_hash)
            ->not->toStartWith(AuthSessionRetention::ANONYMISED_PREFIX);
    });

    it('spares a device whose ended session carries no closing time', function (): void {
        $device = Device::factory()->create(['user_id' => $this->subject->getKey()]);
        ageRetentionRow($device, 'created_at', 400);

        $session = AuthSession::factory()->create([
            'user_id' => $this->subject->getKey(),
            'device_id' => $device->getKey(),
            'status' => AuthSession::STATUS_ENDED,
        ]);
        // Ended, recent, and `ended_at` null — `NULL >= :before` is NULL, so
        // without COALESCE the NOT EXISTS reads true and the fingerprint goes.
        DB::table('auth_sessions')->where('id', $session->getKey())->update(['ended_at' => null]);

        retentionSweepRun();

        expect($device->fresh()->fingerprint_hash)
            ->not->toStartWith(AuthSessionRetention::ANONYMISED_PREFIX);
    });

    it('spares a device born a moment ago with no sessions at all', function (): void {
        // ⛔ THE SIGN-IN RACE. `StartAuthSession` writes the device, mints the
        // token, then writes the session — and in between both NOT EXISTS clauses
        // are satisfied VACUOUSLY. Anonymising here breaks `firstOrNew` on the
        // next sign-in: one machine becomes two under the device limit, and the
        // student is evicted from the computer they are sitting at.
        $device = Device::factory()->create(['user_id' => $this->subject->getKey()]);

        retentionSweepRun();

        expect($device->fresh()->fingerprint_hash)
            ->not->toStartWith(AuthSessionRetention::ANONYMISED_PREFIX);
    });

    it('anonymises a device whose every session is old and ended', function (): void {
        [$device] = agedRetentionPair($this->subject, 400, 300);

        retentionSweepRun();

        // The positive control the four negations above are worth nothing without.
        expect($device->fresh()->fingerprint_hash)
            ->toStartWith(AuthSessionRetention::ANONYMISED_PREFIX);
    });
});

it('produces the same state twice, and reports zero the second time', function (): void {
    agedRetentionPair($this->subject, 400, 300);

    $first = retentionSweepRun();

    $state = AuthSession::query()->orderBy('id')->get(['id', 'ip_hash', 'session_id', 'device_id'])->toArray();

    $second = retentionSweepRun();

    // ⛔ THREE ASSERTIONS, NOT ONE. `RunRetentionSweepJob` catches a throwing
    // category, records it in `findings` and carries on writing
    // `rows_anonymised = 0` — so an arm that explodes on EVERY pass reports zero
    // twice and satisfies "the second run found nothing" perfectly.
    expect($first->rows_anonymised)->toBeGreaterThan(0)
        ->and($first->findings_count)->toBe(0)
        ->and($second->rows_anonymised)->toBe(0)
        ->and($second->findings_count)->toBe(0);

    expect(AuthSession::query()->orderBy('id')->get(['id', 'ip_hash', 'session_id', 'device_id'])->toArray())
        ->toBe($state);
});

it('stops both arms for a held subject and sweeps everybody else', function (): void {
    [$heldDevice, $heldSession] = agedRetentionPair($this->subject, 400, 300);

    $other = User::factory()->create();
    [$otherDevice, $otherSession] = agedRetentionPair($other, 400, 300);

    LegalHold::query()->create([
        'subject_user_id' => $this->subject->getKey(),
        'reason' => 'نزاع قضائيّ قائم',
        'placed_by_user_id' => User::factory()->create()->getKey(),
        'placed_at' => now(),
    ]);

    $heldIp = $heldSession->ip_hash;
    $heldFingerprint = $heldDevice->fingerprint_hash;

    retentionSweepRun();

    // ⚠️ BY VALUE, NEVER BY ROW COUNT: nothing here deletes, so a count is
    // identical whether the hold was honoured or ignored.
    expect($heldSession->fresh()->ip_hash)->toBe($heldIp)
        ->and($heldDevice->fresh()->fingerprint_hash)->toBe($heldFingerprint);

    expect($otherSession->fresh()->ip_hash)->toBeNull()
        ->and($otherDevice->fresh()->fingerprint_hash)
        ->toStartWith(AuthSessionRetention::ANONYMISED_PREFIX);
});

it('reaches both tables on an erasure, through the action the request uses', function (): void {
    $device = Device::factory()->create(['user_id' => $this->subject->getKey()]);

    $ended = AuthSession::factory()->ended()->create([
        'user_id' => $this->subject->getKey(),
        'device_id' => $device->getKey(),
    ]);

    // A live panel session — the ordinary case, since erasure ends no session and
    // revokes no token.
    $livePanel = AuthSession::factory()->create([
        'user_id' => $this->subject->getKey(),
        'device_id' => $device->getKey(),
        'token_id' => null,
        'session_id' => Str::random(40),
    ]);

    // ⛔ THROUGH `FulfilDataRequestJob`, NEVER BY CALLING `erase()` DIRECTLY. A
    // direct call skips `modeFor()`, so the case stays green on a build where
    // erasure has been switched off for the whole of Identity by one mismatched
    // catalogue row.
    $request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );
    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    expect($ended->fresh()->ip_hash)->toBeNull()
        ->and($ended->fresh()->session_id)->toBeNull()
        ->and($device->fresh()->fingerprint_hash)
        ->toStartWith(AuthSessionRetention::ANONYMISED_PREFIX);

    // ⛔ AND THE LIVE PANEL ROW KEEPS ITS HANDLE. `session_id` is the only grip on
    // a panel sign-in (`RecordPanelSession` writes a null `token_id`), and
    // `TerminateAuthSession` ends one through it — so clearing it on a live row
    // leaves a working browser session that neither the owner's «أنهِ» nor
    // `evictDevice()` can close. Spec 037's hole, reopened for every erased
    // account.
    expect($livePanel->fresh()->session_id)->not->toBeNull()
        ->and($livePanel->fresh()->ip_hash)->toBeNull();
});

it('reaches an account that was erased before this feature shipped', function (): void {
    $device = Device::factory()->create(['user_id' => $this->subject->getKey()]);

    $session = AuthSession::factory()->ended()->create([
        'user_id' => $this->subject->getKey(),
        'device_id' => $device->getKey(),
    ]);

    // The marker `erase()` returns on, written without going through the arms —
    // which is exactly the state every account erased before 038 is in.
    DB::table('users')
        ->where('id', $this->subject->getKey())
        ->update(['email' => 'anonymised+'.$this->subject->getKey().'@example.test']);

    $written = (new AuthSessionRetention)->backfillErasedAccounts();

    expect($written)->toBeGreaterThan(0)
        ->and($session->fresh()->ip_hash)->toBeNull()
        ->and($device->fresh()->fingerprint_hash)
        ->toStartWith(AuthSessionRetention::ANONYMISED_PREFIX);

    // Converges: a second pass has nothing left to write.
    expect((new AuthSessionRetention)->backfillErasedAccounts())->toBe(0);
});
