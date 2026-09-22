<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Identity\Jobs\EnforceAuthSessionCapJob;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * Spec 038 · US2 — the cap, and the floor that keeps it from erasing evidence.
 *
 * ⚠️ A FILE OF ITS OWN, deliberately. The sweep and this job are two doors that run
 * independently; measuring them together would make US2 impossible to judge alone.
 *
 * ⛔ AND THE FIXTURE IS BUILT BY QUERY. `created_at` is not fillable, so an "aged"
 * row written inside `create()` is born today — and `ip_hash` has to be cleared
 * explicitly, because the factory now writes one (which is the point: without it
 * every fixture row is born looking already-swept).
 */
function cappedSessionFixture(User $user, int $count, int $endedDaysAgo, bool $anonymised = true): array
{
    $device = Device::factory()->create(['user_id' => $user->getKey()]);

    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $session = AuthSession::factory()->ended()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
        ]);

        DB::table('auth_sessions')->where('id', $session->getKey())->update([
            'created_at' => now()->subDays($endedDaysAgo + 1),
            // Distinct seconds so the ordering is unambiguous unless a case
            // deliberately makes two collide.
            'ended_at' => now()->subDays($endedDaysAgo)->subSeconds($i),
            'ip_hash' => $anonymised ? null : hash('sha256', 'addr'.$i),
        ]);

        $ids[] = (int) $session->getKey();
    }

    return $ids;
}

function anonymisedSessionCount(User $user): int
{
    return AuthSession::query()
        ->where('user_id', $user->getKey())
        ->where('status', AuthSession::STATUS_ENDED)
        ->whereNull('ip_hash')
        ->count();
}

beforeEach(function (): void {
    $this->subject = User::factory()->create();
});

it('trims the anonymised ones past the floor and leaves the rest of the table alone', function (): void {
    // 60 anonymised, a year old — the only rows the cap may reach.
    cappedSessionFixture($this->subject, 60, 400);

    // Recent ended rows, still carrying their address.
    cappedSessionFixture($this->subject, 55, 5, anonymised: false);

    // And more live sessions than the cap, which FR-003 puts out of reach entirely.
    $device = Device::factory()->create(['user_id' => $this->subject->getKey()]);
    AuthSession::factory()->count(55)->create([
        'user_id' => $this->subject->getKey(),
        'device_id' => $device->getKey(),
    ]);

    EnforceAuthSessionCapJob::dispatchSync();

    expect(anonymisedSessionCount($this->subject))->toBe(50);

    expect(AuthSession::query()->where('user_id', $this->subject->getKey())
        ->where('status', AuthSession::STATUS_ENDED)->whereNotNull('ip_hash')->count())
        ->toBe(55);

    expect(AuthSession::query()->where('user_id', $this->subject->getKey())
        ->where('status', AuthSession::STATUS_ACTIVE)->count())
        ->toBe(55);
});

it('keeps the newest, by the moment they ended', function (): void {
    $ids = cappedSessionFixture($this->subject, 60, 400);

    // `cappedSessionFixture` walks backwards in time, so the first ids are the newest.
    $newest = array_slice($ids, 0, 50);

    EnforceAuthSessionCapJob::dispatchSync();

    $survivors = AuthSession::query()
        ->where('user_id', $this->subject->getKey())
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();

    sort($newest);

    expect($survivors)->toBe($newest);
});

it('deletes nothing from rows that still carry an address', function (): void {
    // ⛔ THE CONTROL FOR `ip_hash IS NULL`. Every other case in this file is also
    // protected by the floor, so deleting that condition from the candidate
    // predicate survives all of them — and that mutation is precisely "a row that
    // still holds an address was deleted", which is the state production is in on
    // any night before the backfill has run.
    cappedSessionFixture($this->subject, 60, 400, anonymised: false);

    EnforceAuthSessionCapJob::dispatchSync();

    expect(AuthSession::query()->where('user_id', $this->subject->getKey())->count())->toBe(60);
});

it('deletes nothing inside the age floor', function (): void {
    // Anonymised, over the cap, and 100 days old against a 180-day floor.
    cappedSessionFixture($this->subject, 60, 100);

    EnforceAuthSessionCapJob::dispatchSync();

    expect(anonymisedSessionCount($this->subject))->toBe(60);
});

it('reads a cap of zero as no cap at all', function (): void {
    cappedSessionFixture($this->subject, 60, 400);

    PlatformSettings::set('auth.auth_session_cap_per_user', 0);

    EnforceAuthSessionCapJob::dispatchSync();

    // ⛔ THE ONLY BRANCH THAT CAN EMPTY THE PLATFORM'S SIGN-IN LOG. An operator who
    // clears the field means to switch the feature off; read as "keep zero rows"
    // it deletes everything, irreversibly. «An account with one session» cannot
    // measure this — it is green at any cap ≥ 1.
    expect(anonymisedSessionCount($this->subject))->toBe(60);
});

it('keeps the same rows when two sessions ended in the same second', function (): void {
    cappedSessionFixture($this->subject, 60, 400);

    // Two rows on the cap boundary sharing one `ended_at`: with `id DESC` absent
    // from the ordering, which of them survives flips between runs.
    $boundary = AuthSession::query()
        ->where('user_id', $this->subject->getKey())
        ->orderByDesc('ended_at')
        ->skip(49)
        ->take(2)
        ->pluck('id')
        ->all();

    DB::table('auth_sessions')->whereIn('id', $boundary)
        ->update(['ended_at' => now()->subDays(400)->subSeconds(49)]);

    EnforceAuthSessionCapJob::dispatchSync();

    $first = AuthSession::query()->where('user_id', $this->subject->getKey())
        ->orderBy('id')->pluck('id')->all();

    EnforceAuthSessionCapJob::dispatchSync();

    // ⚠️ THE ID SET, NOT THE COUNT (SC-006). A count is identical under an
    // ordering that changes its mind; only the set shows it.
    expect(AuthSession::query()->where('user_id', $this->subject->getKey())
        ->orderBy('id')->pluck('id')->all())->toBe($first);
});

it('spares a held subject and still trims the person discovered after them', function (): void {
    // The held user is created first, so their id sorts BEFORE the other's in the
    // discovery query's `orderBy('user_id')`.
    $held = $this->subject;
    $other = User::factory()->create();

    cappedSessionFixture($held, 60, 400);
    cappedSessionFixture($other, 60, 400);

    LegalHold::query()->create([
        'subject_user_id' => $held->getKey(),
        'reason' => 'أمر قضائيّ',
        'placed_by_user_id' => User::factory()->create()->getKey(),
        'placed_at' => now(),
    ]);

    EnforceAuthSessionCapJob::dispatchSync();

    // ⛔ THE SECOND HALF IS THE POINT. Skipping the held user AFTER selection
    // returns a page shorter than the batch, the loop concludes it is finished,
    // and everybody behind them is never processed — not tonight and not any
    // night. One held account alone cannot show that.
    expect(anonymisedSessionCount($held))->toBe(60)
        ->and(anonymisedSessionCount($other))->toBe(50);
});

it('ends nobody s live session anywhere on the platform', function (): void {
    cappedSessionFixture($this->subject, 60, 400);

    // A second account the job never touches — with one account, "platform-wide"
    // and "this fixture" are the same set, and a build that ended everybody
    // else's sessions would pass.
    $bystander = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $bystander->getKey()]);
    AuthSession::factory()->count(3)->create([
        'user_id' => $bystander->getKey(),
        'device_id' => $device->getKey(),
    ]);

    $before = AuthSession::query()->where('status', AuthSession::STATUS_ACTIVE)->count();

    EnforceAuthSessionCapJob::dispatchSync();

    expect(AuthSession::query()->where('status', AuthSession::STATUS_ACTIVE)->count())->toBe($before)
        ->and(AuthSession::query()->where('user_id', $bystander->getKey())->count())->toBe(3);
});

it('leaves a capped account able to count its sign-ins across at least three months', function (): void {
    // Seven months of history: two sign-ins a day for 210 days.
    $device = Device::factory()->create(['user_id' => $this->subject->getKey()]);

    for ($day = 0; $day < 210; $day++) {
        foreach ([0, 1] as $n) {
            $session = AuthSession::factory()->ended()->create([
                'user_id' => $this->subject->getKey(),
                'device_id' => $device->getKey(),
            ]);

            DB::table('auth_sessions')->where('id', $session->getKey())->update([
                'created_at' => now()->subDays($day + 1),
                'ended_at' => now()->subDays($day)->subHours($n),
                // Everything past the 90-day retention has been anonymised by the
                // sweep; everything newer still carries its address.
                'ip_hash' => $day > 90 ? null : hash('sha256', 'a'.$day.$n),
            ]);
        }
    }

    EnforceAuthSessionCapJob::dispatchSync();

    $months = AuthSession::query()
        ->where('user_id', $this->subject->getKey())
        ->pluck('ended_at')
        ->map(static fn (mixed $at): string => $at->format('Y-m'))
        ->unique()
        ->count();

    // ⚠️ SC-004 IS KEPT BY THE FLOOR, NOT BY THE CAP: everything newer than 180
    // days survives however many rows there are. Drop the floor to the retention
    // and this criterion goes with it.
    expect($months)->toBeGreaterThanOrEqual(3);
});
