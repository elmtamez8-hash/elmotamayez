<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Policies\AssistantAssignmentPolicy;
use App\Modules\Community\Support\EloquentAssistantScopeDirectory;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Modules\Module;
use App\Shared\Modules\ModulesServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 010 — assistants, conversations, moderation, periodic reviews, report
 * cards and announcements.
 *
 * Auto-discovered by {@see ModulesServiceProvider}; never register it by hand in
 * bootstrap/providers.php.
 *
 * ⚠️ THE ONE NEW TECHNICAL INPUT — LIVE BROADCASTING — GETS NO ABSTRACTION OF
 * OURS, and that is a departure from 004, 005 and 017 on purpose. Those wrapped a
 * video host and a room provider because Laravel owns no driver layer for either.
 * It owns one for broadcasting, and `reverb` is a driver in it: moving to a
 * managed provider is a line of configuration, so an interface here would be a
 * second driver layer over the first.
 *
 * ⚠️ AND THE DATABASE IS THE SOURCE, THE SOCKET IS AN ACCELERATOR. A message is
 * written and read from the table; the broadcast is a queued `ShouldBroadcast`
 * event that carries an IDENTIFIER and no payload. Reverse that and an outage of
 * the socket becomes a failed send — `SC-015` says it must not be.
 */
class CommunityServiceProvider extends Module
{
    protected string $name = 'Community';

    public function register(): void
    {
        parent::register();

        /*
        | ⚠️ `scoped()`, NOT `bind()` AND NOT `singleton()` — and the two
        | departures are for opposite reasons.
        |
        | Not `bind()`: `isAssistantIn()` is the key of the financial wall's
        | `Gate::before`, so it is asked on every permission check — dozens of
        | times on one Filament page. A fresh instance per resolution throws the
        | memo away each time and turns the wall into a query per check.
        |
        | ⚠️ AND NOT `singleton()`, WHICH WOULD MAKE REVOCATION STOP BEING INSTANT
        | IN A QUEUE WORKER. A singleton lives as long as the container, and a
        | worker's container outlives the job — so an assistant revoked at noon
        | would keep passing a cached `true` until the worker restarted. `scoped()`
        | is flushed between jobs and between Octane requests, which is what "per
        | request" actually means here. `SC-003` is measured over HTTP and would
        | never have seen it.
        |
        | `PlatformStaffDirectory` is registered the same way, for the same reason.
        */
        $this->app->scoped(AssistantScopeDirectory::class, EloquentAssistantScopeDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        /*
        | ⚠️ REGISTERED EXPLICITLY, NEVER LEFT TO THE GUESSER. Laravel's policy
        | guesser fails OPEN — no policy found means "no policy applies" — and a
        | deny-only test passes just as happily against a missing registration as
        | against a working one. That is how `taxonomy.manage` shipped in 009
        | guarding nothing at all.
        */
        Gate::policy(AssistantAssignment::class, AssistantAssignmentPolicy::class);
    }
}
