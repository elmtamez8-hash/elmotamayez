<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
| Two housekeeping commands nothing ran: Horizon's metrics snapshot (without it
| the Metrics tab is empty) and the `failed_jobs` prune (without it the table
| grows for ever). Asserted on the schedule itself, the way the heartbeat is.
*/

function queueHousekeepingEvent(string $needle): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $needle));
}

it('snapshots Horizon metrics every five minutes', function (): void {
    expect(queueHousekeepingEvent('horizon:snapshot')?->expression)->toBe('*/5 * * * *');
});

it('prunes failed jobs older than thirty days, daily', function (): void {
    $event = queueHousekeepingEvent('queue:prune-failed');

    expect($event?->expression)->toBe('50 2 * * *')
        ->and((string) $event?->command)->toContain('--hours=720');
});
