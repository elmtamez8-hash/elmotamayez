<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Identity\Jobs\EnforceAuthSessionCapJob;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    // Read the clock once: `now()` per row let a second tick between two rows
    // and gave them the same `ended_at`, so which one survived was luck (CI
    // flaked on it twice on 2026-09-24).
    $now = now();

    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $session = AuthSession::factory()->ended()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
        ]);

        DB::table('auth_sessions')->where('id', $session->getKey())->update([
            'created_at' => $now->copy()->subDays($endedDaysAgo + 1),
            // Distinct seconds so the ordering is unambiguous unless a case
            // deliberately makes two collide.
            'ended_at' => $now->copy()->subDays($endedDaysAgo)->subSeconds($i),
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

it('breaks a tie on the moment by the newer row, not by whatever the engine returns', function (): void {
    $ids = cappedSessionFixture($this->subject, 60, 400);

    /*
    | ⛔ THE PAIR ON THE CAP BOUNDARY, TIED ON `ended_at`.
    |
    | `cappedSessionFixture` walks backwards in time, so `$ids` ascends while
    | `ended_at` descends: index 49 is the last survivor and index 50 the first
    | casualty. Give them one `ended_at` and the ONLY thing left to separate
    | them is `orderByDesc('id')` — under which the higher id sorts first and
    | therefore lives.
    |
    | ⚠️ THE PREVIOUS SPELLING OF THIS CASE MEASURED NOTHING. It trimmed,
    | then trimmed again, and compared the survivors to themselves — by the
    | second run nobody is over the cap, so nothing is deleted and no ordering
    | decision is ever taken twice. Deleting the tiebreak left all nine cases in
    | this file green. Naming WHICH row must survive is what bites, because a
    | database is deterministic for one query over one dataset: two identical
    | runs agree even with no tiebreak at all.
    */
    $lastSurvivor = $ids[49];
    $firstCasualty = $ids[50];

    DB::table('auth_sessions')->whereIn('id', [$lastSurvivor, $firstCasualty])
        ->update(['ended_at' => now()->subDays(400)->subSeconds(49)]);

    EnforceAuthSessionCapJob::dispatchSync();

    $survivors = AuthSession::query()->where('user_id', $this->subject->getKey())
        ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

    expect($survivors)->toHaveCount(50)
        // The higher id of the tied pair — and the lower one is gone.
        ->and($survivors)->toContain($firstCasualty)
        ->and($survivors)->not->toContain($lastSurvivor);
});

it('deletes nothing on a second pass', function (): void {
    cappedSessionFixture($this->subject, 60, 400);

    EnforceAuthSessionCapJob::dispatchSync();

    $first = AuthSession::query()->where('user_id', $this->subject->getKey())
        ->orderBy('id')->pluck('id')->all();

    EnforceAuthSessionCapJob::dispatchSync();

    // ⚠️ THE ID SET, NOT THE COUNT. A count is identical under an
    // implementation that deleted one row and spared another.
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

it('walks past the first discovery page without skipping the accounts behind it', function (): void {
    /*
    | ⛔ THE ONLY CASE THAT CROSSES `DISCOVERY_PAGE`, AND NOTHING ELSE IN THIS
    | FILE CAN SEE THE DEFECT IT GUARDS. Paging the discovery query by OFFSET is
    | green for every fixture smaller than one page — which is every other case
    | here — because the second page is never asked for.
    |
    | The defect: `trim()` leaves each account holding exactly `$cap` candidates,
    | so `having('total', '>', $cap)` stops matching it and the result set SHRINKS
    | under the walk. An offset then steps over a set that has lost precisely the
    | rows it was meant to step past, and the accounts behind the first page are
    | never reached — while the run logs its partial work as a success.
    |
    | ⚠️ THE CAP IS SET TO 1 SO THE FIXTURE STAYS SMALL. At the shipped 50 this
    | case would need 201 × 51 rows to say the same thing; the paging has nothing
    | to do with the size of the cap.
    */
    PlatformSettings::set('auth.auth_session_cap_per_user', 1);

    $users = User::factory()->count(201)->create();

    $devices = [];
    $sessions = [];
    $now = now();

    foreach ($users as $i => $user) {
        $deviceId = $i + 1;

        $devices[] = [
            'id' => $deviceId,
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'fingerprint_hash' => hash('sha256', 'd'.$deviceId),
            'label' => 'fixture',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Two anonymised, aged rows each — one over a cap of 1.
        foreach ([0, 1] as $n) {
            $sessions[] = [
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'device_id' => $deviceId,
                'status' => AuthSession::STATUS_ENDED,
                'ip_hash' => null,
                'created_at' => $now->copy()->subDays(401),
                'updated_at' => $now,
                'ended_at' => $now->copy()->subDays(400)->subSeconds($n),
            ];
        }
    }

    DB::table('devices')->insert($devices);

    foreach (array_chunk($sessions, 200) as $chunk) {
        DB::table('auth_sessions')->insert($chunk);
    }

    EnforceAuthSessionCapJob::dispatchSync();

    /*
    | ⚠️ THE ACCOUNTS STILL OVER THE CAP, NOT THE TOTAL ROW COUNT. A total is
    | off by one row out of 402 and reads as rounding; the set of accounts the
    | job failed to reach is the thing that was actually lost.
    */
    $untrimmed = AuthSession::query()
        ->select('user_id')
        ->selectRaw('count(*) as total')
        ->groupBy('user_id')
        ->having('total', '>', 1)
        ->pluck('user_id')
        ->all();

    expect($untrimmed)->toBe([]);
});
