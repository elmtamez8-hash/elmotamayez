<?php

declare(strict_types=1);

namespace App\Modules\Identity\Jobs;

use App\Modules\Identity\Actions\TerminateAuthSession;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\IdleSessionGuard;
use App\Modules\Identity\Support\SessionEndReason;
use App\Shared\Traits\RunsAlone;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Ends the bearer-token sessions nobody has used for `auth.session_idle_days`.
 *
 * ⛔ {@see IdleSessionGuard} ONLY FIRES WHEN A TOKEN IS PRESENTED, and the token
 * that matters most is the one that never is again — the laptop that was sold,
 * the shared computer somebody walked away from. Its `personal_access_tokens`
 * row lived for ever and its `auth_sessions` row stayed `active` for ever, which
 * is not housekeeping: `DeviceRegistry::enforceLimit()` counts devices among
 * ACTIVE sessions, so a machine abandoned in January kept occupying one of the
 * owner's device slots, and the devices screen kept listing it as signed in.
 * `EnforceAuthSessionCapJob` and the retention sweep both select ENDED rows
 * only, so nothing else reached it.
 *
 * ⚠️ THE PREDICATE IS THE GUARD'S, NOT `last_active_at`. That column is a copy
 * the guard writes at most every five minutes; the guard itself judges
 * `last_used_at ?? created_at` on the token, against {@see IdleSessionGuard::cutoff()}
 * — the same number, read from the same place, so the nightly sweep and the
 * request-time check cannot disagree about who is idle.
 *
 * ⚠️ TOKEN SESSIONS ONLY (`token_id IS NOT NULL`). A panel sign-in has no token
 * and its `last_active_at` is frozen at sign-in, so a 30-day rule on it would
 * destroy the web session of an operator who used `/admin` every day. The panel
 * is governed by `config/session.php`'s lifetime instead.
 *
 * ⚠️ THROUGH {@see TerminateAuthSession}, never a bare delete: it removes the
 * token AND writes `ended_reason = idle`, which is what the sign-in screen
 * answers «انتهت الجلسة لأنها لم تُستخدم مدّة طويلة» from.
 */
class EndIdleAuthSessionsJob implements ShouldQueue
{
    use Queueable, RunsAlone;

    /** Sessions read per page. */
    private const PAGE = 500;

    /**
     * A ceiling on one night's work. The first run after this ships meets every
     * token abandoned since spec 004, and a backlog left over is simply the next
     * night's — the rule is already enforced at request time.
     */
    private const MAX_PER_RUN = 20000;

    public int $tries = 1;

    public function handle(IdleSessionGuard $guard, TerminateAuthSession $terminate): void
    {
        $cutoff = $guard->cutoff();

        if ($cutoff === null) {
            return;
        }

        $ended = $this->endIdleSessions($cutoff, $terminate);
        $pruned = $this->pruneOrphanTokens($cutoff);

        // Counts only — see the catch below for why nothing else goes in a log.
        Log::info('identity.idle_sessions.swept', [
            'sessions_ended' => $ended,
            'tokens_pruned' => $pruned,
        ]);
    }

    private function endIdleSessions(CarbonInterface $cutoff, TerminateAuthSession $terminate): int
    {
        $ended = 0;
        $seen = 0;

        /*
        | ⛔ A KEYSET CURSOR, NEVER AN OFFSET: every row ended leaves the predicate,
        | so an offset would step over as many rows as the previous page fixed —
        | `EnforceAuthSessionCapJob`'s defect, reached through a status. The cursor
        | also moves past a row whose termination threw, so one bad row is retried
        | tomorrow instead of stalling the walk on itself.
        */
        $cursor = 0;

        while ($seen < self::MAX_PER_RUN) {
            $sessions = AuthSession::query()
                ->active()
                ->whereNotNull('token_id')
                ->whereIn('token_id', fn (QueryBuilder $q) => $this->idleTokenIds($q, $cutoff))
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(self::PAGE)
                ->get();

            if ($sessions->isEmpty()) {
                break;
            }

            $seen += $sessions->count();
            $cursor = (int) $sessions->last()->getKey();

            foreach ($sessions as $session) {
                try {
                    $terminate->handle($session, SessionEndReason::Idle);
                    $ended++;
                } catch (Throwable $e) {
                    // The CLASS, never `getMessage()`: a QueryException carries its
                    // bindings, and the full trace belongs in `failed_jobs`, not in
                    // a line shipped to the monitoring vendor (LogHygieneTest).
                    Log::error('identity.idle_sessions.session_failed', [
                        'session_id' => $session->getKey(),
                        'exception' => $e::class,
                    ]);
                }
            }
        }

        return $ended;
    }

    /**
     * Idle tokens with no ACTIVE session behind them: minted before sessions
     * existed, or left behind by a path that ended the row and not the credential.
     * Nothing will ever present them to the guard on purpose.
     */
    private function pruneOrphanTokens(CarbonInterface $cutoff): int
    {
        $pruned = 0;

        do {
            /*
            | `->limit()->delete()` is `DELETE … LIMIT` on MySQL and a rowid rewrite
            | on SQLite. The `NOT EXISTS` reads a DIFFERENT table, so this is not
            | the self-referencing delete MySQL refuses with ERROR 1093.
            */
            $batch = PersonalAccessToken::query()
                ->where(fn (Builder $q): Builder => $this->idleWhere($q, $cutoff))
                ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                    ->from('auth_sessions')
                    ->whereColumn('auth_sessions.token_id', 'personal_access_tokens.id')
                    ->where('auth_sessions.status', AuthSession::STATUS_ACTIVE))
                ->limit(self::PAGE)
                ->delete();

            $pruned += $batch;
        } while ($batch > 0 && $pruned < self::MAX_PER_RUN);

        return $pruned;
    }

    private function idleTokenIds(QueryBuilder $q, CarbonInterface $cutoff): QueryBuilder
    {
        return $q->select('id')
            ->from('personal_access_tokens')
            ->where(fn (QueryBuilder $w): QueryBuilder => $this->idleWhere($w, $cutoff));
    }

    /**
     * `COALESCE(last_used_at, created_at) < cutoff`, spelled as two ranges so
     * neither column is wrapped in a function.
     *
     * @template TBuilder of Builder<PersonalAccessToken>|QueryBuilder
     *
     * @param  TBuilder  $q
     * @return TBuilder
     */
    private function idleWhere(Builder|QueryBuilder $q, CarbonInterface $cutoff): Builder|QueryBuilder
    {
        return $q->where('last_used_at', '<', $cutoff)
            ->orWhere(fn ($w) => $w->whereNull('last_used_at')->where('created_at', '<', $cutoff));
    }
}
