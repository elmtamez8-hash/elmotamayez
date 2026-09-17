<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Models\User;
use App\Modules\Identity\Actions\RecordPanelSession;
use Illuminate\Auth\Events\Login;

/**
 * The panel has no controller of ours to hook — Filament owns its login page, and
 * the handoff bridge is a second entrance to the same guard. The framework's own
 * `Login` event is the one place both arrive, which is why this is a listener
 * rather than a line in either.
 *
 * ⚠️ NOT QUEUED, AND THAT IS THE POINT. It must read the session id of the request
 * it is running inside; a queued listener has no session at all and would record
 * null on every panel sign-in — a row that lists correctly and ends nothing.
 *
 * ⚠️ AND THE GUARD IS CHECKED. `Login` fires for every guard, and Sanctum's
 * stateful pipeline plus every `actingAs` in the suite would otherwise mint rows
 * for sign-ins that never happened. `web` is the panel's guard and the only one
 * this answers for.
 */
class RecordPanelSignIn
{
    public function __construct(private readonly RecordPanelSession $record) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        $request = request();

        // A console sign-in (a seeder, `tinker`, an artisan command) has a Request
        // object but no session behind it. There is no device to record and nobody
        // to evict, and forcing one would start a session on the command line.
        if (! $request->hasSession()) {
            return;
        }

        $this->record->handle($event->user, $request, $request->session()->getId());
    }
}
