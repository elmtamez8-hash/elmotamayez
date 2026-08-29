<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Spec 011 · T028 — is this feature on, here?
 *
 * One row per (key, workspace), plus a row per key at `workspace_id = 0` that is
 * the platform default. A workspace row overrides it; an unknown key is off.
 *
 * ⚠️ ONE QUERY FOR THE WHOLE MAP, NOT ONE PER ASK. A flag is read from a
 * Resource, from a policy, from a blade view and from the middle of a loop —
 * `where('key', $key)` per call is an N+1 by construction, which is the same
 * mistake `WithholdingReader` and `ReadRanksFor` each had to be rewritten out
 * of. Both scopes come back in a single `whereIn('workspace_id', [$ws, 0])`.
 *
 * ⚠️ `scoped()`, AND NEITHER NEIGHBOUR WOULD DO — the same two opposite reasons
 * `AssistantScopeDirectory` is bound that way. Not `bind()`: this is asked
 * dozens of times in a page and a fresh instance re-runs the query every time.
 * Not `singleton()`: a queue worker's container outlives the job, so a flag
 * switched off at noon would stay lit in that worker until it restarted — and a
 * feature flag is the one thing whose whole purpose is to take effect NOW.
 *
 * ⚠️ AND `(int) null === 0` ADDRESSES THE PLATFORM ROW. Reading is safe — a null
 * workspace is a guest, and the default is the right answer for one. WRITING is
 * not: an unresolved uuid on a write would turn a feature off for every
 * workspace on the platform from one teacher's screen. Whatever writes here
 * guards with `abort_if` first, exactly as `UnlockRuleController` does over the
 * same sentinel.
 */
class Flags
{
    public const PLATFORM = 0;

    /**
     * Per workspace id, `key => enabled`. Filled on first ask and kept for the
     * life of the request (see the `scoped()` note above).
     *
     * @var array<int, array<string, bool>>
     */
    private array $memo = [];

    public function enabled(string $key, ?int $workspaceId): bool
    {
        return $this->map($workspaceId)[$key] ?? false;
    }

    /**
     * The whole map for a scope, for a caller that reads several — a settings
     * screen, or a payload that carries what the client may render.
     *
     * @return array<string, bool>
     */
    public function map(?int $workspaceId): array
    {
        $scope = (int) $workspaceId;

        if (isset($this->memo[$scope])) {
            return $this->memo[$scope];
        }

        $flags = [];

        /*
         * Ordered so the workspace row is read LAST and overwrites the platform
         * default, rather than being dropped by it. `orderBy('workspace_id')`
         * puts 0 first by definition, which is why the sentinel is 0 and not a
         * number chosen for looking unlikely.
         *
         * No global scope is involved: this table deliberately carries no
         * `BelongsToWorkspace`, because the row every reader falls back to
         * belongs to no workspace at all.
         */
        $rows = DB::table('feature_flags')
            ->whereIn('workspace_id', array_unique([self::PLATFORM, $scope]))
            ->orderBy('workspace_id')
            ->get(['key', 'enabled']);

        foreach ($rows as $row) {
            $flags[(string) $row->key] = (bool) $row->enabled;
        }

        return $this->memo[$scope] = $flags;
    }

    /**
     * Drop the memo — for a test that flips a flag mid-case, and for nothing
     * else. Production reads one request's worth and the request ends.
     */
    public function forget(): void
    {
        $this->memo = [];
    }
}
