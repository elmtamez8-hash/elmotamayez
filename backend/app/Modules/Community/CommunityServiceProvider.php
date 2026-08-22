<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Shared\Modules\Module;
use App\Shared\Modules\ModulesServiceProvider;

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
}
