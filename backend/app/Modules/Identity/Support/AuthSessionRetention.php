<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Compliance\Support\Anonymiser;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The anonymisation primitives spec 038 is built out of.
 *
 * Three callers: the age arm of {@see IdentityPersonalData::expire()}, the erasure
 * arm of {@see IdentityPersonalData::erase()}, and the one-off migration that
 * reaches accounts erased before this shipped. One spelling for all three — two
 * would agree until the first one moved.
 *
 * ⛔ THE ANONYMISED VALUE IS BUILT IN PHP, NEVER IN SQL, and this is the sharpest
 * line in the file. `'anonymised:' || id` is string concatenation on SQLite — where
 * this entire suite runs — and LOGICAL OR on MySQL, which has no `PIPES_AS_CONCAT`
 * in the sql_mode Laravel writes from `'strict' => true`. So production would store
 * `'1'` in every row: it collides with `unique(user_id, fingerprint_hash)` at a
 * user's second device (rolling back the whole erasure transaction, so no name, no
 * email and no phone is anonymised either), and it never converges, because
 * `'1' NOT LIKE 'anonymised:%'` is true every night for ever. Green on every gate.
 *
 * ⚠️ AND NOT `CONCAT()` EITHER: SQLite grew that function in 3.44, and the CI
 * runner's version is not ours to assume. The precedent for building it here is
 * {@see Anonymiser::email()}, one module over.
 */
final class AuthSessionRetention
{
    /** The readable name of the row a session is re-pointed at (FR-013). */
    public const TOMBSTONE_LABEL = 'جهازٌ لم تعدْ تفاصيلُه محفوظة';

    /** What an anonymised fingerprint always starts with. */
    public const ANONYMISED_PREFIX = 'anonymised:';

    /**
     * One fingerprint-less device row per user, created on first need.
     *
     * ⚠️ `'anonymised:u{id}'` IS UNIQUE BY CONSTRUCTION under
     * `unique(user_id, fingerprint_hash)`, so no number of devices can collide with
     * it, and `firstOrCreate` makes the whole arm converge.
     *
     * ⚠️ IT NEVER MATCHES A REAL BROWSER: `DeviceRegistry::resolve()` looks up a
     * 64-character SHA-256, which this shape cannot be. And it never enters the
     * device limit, because `enforceLimit()` counts devices among ACTIVE sessions
     * and the sweep re-points ended ones only.
     */
    public function tombstoneFor(int $userId): Device
    {
        return Device::query()->firstOrCreate(
            ['user_id' => $userId, 'fingerprint_hash' => self::ANONYMISED_PREFIX.'u'.$userId],
            ['label' => self::TOMBSTONE_LABEL],
        );
    }

    /**
     * The age arm for `auth_sessions` — ended rows past the retention.
     *
     * @param  list<int>  $exemptUserIds
     */
    public function anonymiseSessionsOlderThan(CarbonImmutable $before, array $exemptUserIds, int $limit): int
    {
        $sessions = AuthSession::query()
            ->where('status', AuthSession::STATUS_ENDED)
            /*
            | ⛔ `ended_at`, NOT `created_at` — a deliberate departure from the
            | contract's own docblock, written down rather than left silent: a
            | session opened a year ago and closed yesterday is a day-old record.
            |
            | ⚠️ AND `COALESCE`, one spelling with the device arm below. The column
            | is nullable, and `NULL < :before` is NULL — so a row with no closing
            | time would keep its address FOR EVER. Both writers of `ended`
            | (`TerminateAuthSession`, `RevokeTeacherSessions`) do set it today, so
            | the fallback is unreachable; it stays so there are not two spellings.
            */
            ->whereRaw('COALESCE(ended_at, created_at) < ?', [$before])
            // The candidate and the stopping condition in one: an already-swept row
            // is never selected again, which is what makes the arm idempotent.
            ->whereNotNull('ip_hash')
            ->when($exemptUserIds !== [], fn (Builder $q): Builder => $q->whereNotIn('user_id', $exemptUserIds))
            ->limit($limit)
            ->get(['id', 'user_id']);

        if ($sessions->isEmpty()) {
            return 0;
        }

        $done = 0;

        foreach ($sessions->groupBy('user_id') as $userId => $rows) {
            $tombstone = $this->tombstoneFor((int) $userId);

            $done += AuthSession::query()
                ->whereIn('id', $rows->pluck('id')->all())
                ->update([
                    'device_id' => $tombstone->getKey(),
                    'ip_hash' => null,
                    // FR-015 — added 2026-09-17 and absent from every inventory
                    // written for this table. It identifies one browser.
                    'session_id' => null,
                ]);
        }

        return $done;
    }

    /**
     * The age arm for `devices` — a fingerprint nobody is using any more.
     *
     * @param  list<int>  $exemptUserIds
     */
    public function anonymiseDevicesOlderThan(CarbonImmutable $before, array $exemptUserIds, int $limit): int
    {
        $devices = Device::query()
            ->where('fingerprint_hash', 'not like', self::ANONYMISED_PREFIX.'%')
            ->when($exemptUserIds !== [], fn (Builder $q): Builder => $q->whereNotIn('user_id', $exemptUserIds))
            /*
            | ⛔ THE ROW'S OWN AGE CLOSES A REAL RACE. `StartAuthSession` writes the
            | device, then mints the token, then writes the session — and between
            | the first and the last the device has ZERO sessions, so both
            | `NOT EXISTS` clauses below are satisfied VACUOUSLY. Anonymising it
            | there breaks `firstOrNew`'s match on the next sign-in, one machine
            | becomes two under the device limit, and the student is evicted from
            | the computer they are sitting at — the exact harm FR-007 exists to
            | prevent, produced by FR-007's own conditions.
            */
            ->where('created_at', '<', $before)
            ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                ->from('auth_sessions')
                ->whereColumn('auth_sessions.device_id', 'devices.id')
                ->where('auth_sessions.status', AuthSession::STATUS_ACTIVE))
            /*
            | ⛔ `COALESCE` AGAIN, AND HERE IT FAILS DANGEROUS RATHER THAN SAFE:
            | `NULL >= :before` is NULL, so a session with no closing time would not
            | be seen, the `NOT EXISTS` would read true, and a device carrying a
            | recent sign-in would have its fingerprint wiped.
            */
            ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                ->from('auth_sessions')
                ->whereColumn('auth_sessions.device_id', 'devices.id')
                ->whereRaw('COALESCE(auth_sessions.ended_at, auth_sessions.created_at) >= ?', [$before]))
            ->limit($limit)
            ->get(['id']);

        $done = 0;

        foreach ($devices as $device) {
            $done += Device::query()
                ->whereKey($device->getKey())
                ->update(['fingerprint_hash' => self::ANONYMISED_PREFIX.$device->getKey()]);
        }

        return $done;
    }

    /**
     * The erasure arm — everything this account has, on request.
     *
     * ⛔ `session_id` AND `device_id` TAKE ENDED ROWS ONLY, AND THAT RESTRICTION IS
     * LOAD-BEARING TWICE OVER.
     *
     * `session_id` is the ONLY handle on a panel sign-in — `RecordPanelSession`
     * writes a null `token_id` — and `TerminateAuthSession` ends one with
     * `if ($session->session_id !== null) Session::getHandler()->destroy(...)`.
     * Nulling it on a LIVE row makes that session unendable from either door, the
     * owner's «أنهِ» and `DeviceRegistry::evictDevice()` alike, while the browser
     * keeps working: spec 037's hole, reopened for every erased account. And
     * erasure ends no session and revokes no token, so an erased teacher with an
     * open `/admin` tab is the ordinary case, not the exotic one.
     *
     * Re-pointing LIVE rows at the tombstone switches the device limit off for that
     * account outright: `enforceLimit()` counts DISTINCT `device_id` among active
     * sessions, so four machines collapse into one and nothing is ever evicted.
     *
     * `ip_hash` carries neither hazard and is cleared on every row.
     */
    public function eraseFor(int $userId): int
    {
        $tombstone = $this->tombstoneFor($userId);

        $done = AuthSession::query()
            ->where('user_id', $userId)
            ->whereNotNull('ip_hash')
            ->update(['ip_hash' => null]);

        $done += AuthSession::query()
            ->where('user_id', $userId)
            ->where('status', AuthSession::STATUS_ENDED)
            ->where(fn (Builder $q): Builder => $q
                ->whereNotNull('session_id')
                ->orWhere('device_id', '!=', $tombstone->getKey()))
            ->update([
                'device_id' => $tombstone->getKey(),
                'session_id' => null,
            ]);

        /*
        | ⚠️ THE TOMBSTONE IS EXCLUDED FROM ITS OWN ARM. Without this clause the row
        | created one statement above is rewritten from `anonymised:u{user}` to
        | `anonymised:{device}`, `firstOrCreate` no longer finds it, and the next
        | erasure mints a SECOND tombstone — so FR-013's "one row per user" quietly
        | stops being true and the arm stops converging.
        */
        $done += Device::query()
            ->where('user_id', $userId)
            ->where('fingerprint_hash', 'not like', self::ANONYMISED_PREFIX.'%')
            ->get(['id'])
            ->sum(fn (Device $device): int => Device::query()
                ->whereKey($device->getKey())
                ->update(['fingerprint_hash' => self::ANONYMISED_PREFIX.$device->getKey()]));

        return $done;
    }

    /**
     * FR-014 — the accounts erased before this feature shipped.
     *
     * `erase()` returns 0 above its own transaction for a user already carrying the
     * anonymisation marker, so nothing would ever reach those rows again: no new
     * erasure request arrives for them, and the age arm touches only OLD ENDED
     * sessions while an erased account may still hold a live one.
     *
     * ⛔ EXTRACTED OUT OF THE MIGRATION ON PURPOSE. A migration runs inside
     * `migrate:fresh`, before any fixture exists, so a body written inline is
     * unreachable by every test in the tree — and its three hazards (`chunkById`,
     * raw `DB::table()`, explicit uuid and timestamps) would have no coverage at
     * all.
     *
     * ⚠️ RAW QUERIES, NO MODELS: a migration speaks the schema of its own date and
     * a model speaks today's. `2026_08_29_003100_backfill_referral_catalogue_action`
     * cost this repository 690 of 690 tests by forgetting that.
     *
     * ⚠️ AND `chunkById`, NEVER `chunk`: the predicate shrinks under the walk, so
     * offset paging skips as many rows as the previous page fixed — and reports
     * success.
     */
    public function backfillErasedAccounts(): int
    {
        $done = 0;

        DB::table('users')
            ->where('email', 'like', 'anonymised+%')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function (iterable $users) use (&$done): void {
                foreach ($users as $user) {
                    $done += $this->backfillOne((int) $user->id);
                }
            });

        return $done;
    }

    private function backfillOne(int $userId): int
    {
        $fingerprint = self::ANONYMISED_PREFIX.'u'.$userId;

        $tombstoneId = DB::table('devices')
            ->where('user_id', $userId)
            ->where('fingerprint_hash', $fingerprint)
            ->value('id');

        if ($tombstoneId === null) {
            /*
            | ⚠️ `uuid` AND THE TIMESTAMPS ARE PASSED EXPLICITLY. A raw insert boots
            | no model, so `HasUuid` never fires — and MySQL downgrades the NOT NULL
            | violation to a warning and stores `''`, after which every later row on
            | the platform collides with it on `unique(uuid)` and is read as a
            | duplicate. The rule `CreditLedger::writeEntry()` already wrote down.
            */
            $tombstoneId = DB::table('devices')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'fingerprint_hash' => $fingerprint,
                'label' => self::TOMBSTONE_LABEL,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $done = DB::table('auth_sessions')
            ->where('user_id', $userId)
            ->whereNotNull('ip_hash')
            ->update(['ip_hash' => null, 'updated_at' => now()]);

        // Ended rows only — see `eraseFor()` for why a live panel session must keep
        // its `session_id` and its own device.
        $done += DB::table('auth_sessions')
            ->where('user_id', $userId)
            ->where('status', AuthSession::STATUS_ENDED)
            ->where(fn (QueryBuilder $q): QueryBuilder => $q
                ->whereNotNull('session_id')
                ->orWhere('device_id', '!=', $tombstoneId))
            ->update(['device_id' => $tombstoneId, 'session_id' => null, 'updated_at' => now()]);

        foreach (DB::table('devices')
            ->where('user_id', $userId)
            ->where('fingerprint_hash', 'not like', self::ANONYMISED_PREFIX.'%')
            ->select('id')
            ->get() as $device) {
            $done += DB::table('devices')
                ->where('id', $device->id)
                ->update([
                    'fingerprint_hash' => self::ANONYMISED_PREFIX.$device->id,
                    'updated_at' => now(),
                ]);
        }

        return $done;
    }
}
