<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\MarkAbsenteesJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Opens the room for a session, once.
 *
 * Idempotent on both sides: the provider is contractually required not to make
 * a second room for a session that already has one, and this refuses to
 * overwrite a room id it already holds. A retried request must not strand half
 * the class in a room nobody else is told about.
 */
class OpenBroadcastRoom extends Action
{
    public function __construct(
        /*
         * ⚠️ THE CONFIGURED ONE, AND THIS IS THE ONE PLACE THAT IS CORRECT.
         *
         * Every other consumer resolves the provider from the session's own
         * `broadcast_provider` column — but this Action runs BEFORE any room
         * exists, so there is no column to read yet, and it is this method that
         * writes it. The media module carries the same split for the same reason:
         * a question asked before a row exists is a different question.
         */
        private readonly BroadcastProviderInterface $provider,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(ClassSession $session): ClassSession
    {
        if ($session->status->isTerminal()) {
            throw new DomainException('لا يمكن فتح غرفة لحصة منتهية أو ملغاة.');
        }

        if ($session->status === ClassSessionStatus::Suspended) {
            throw new DomainException('هذه الحصة معلّقة بفترة تجميد.');
        }

        if ($session->room_closed_at !== null) {
            // Reopening would make every ticket issued before the close valid
            // again, which is precisely what FR-015 forbids.
            throw new DomainException('أُغلقت غرفة هذه الحصة.');
        }

        if ($session->broadcast_room_id !== null) {
            return $session;
        }

        $room = $this->provider->createRoom($session);

        /*
         * ⚠️ A CONDITIONAL UPDATE, AND IT WAS A READ FOLLOWED BY A WRITE — the
         * idiom this repository already uses for a seat, for `captured_order_id`
         * and for the recording claim, missing from the one place a DOUBLE TAP
         * reaches it.
         *
         * Two host tabs, or one impatient press of «دخول الغرفة», put two
         * PHP-FPM workers here at once. Both read null, both create the room
         * (the adapter's create is idempotent by room name, so that half is
         * harmless), both write — and both dispatch a
         * `CloseClassSessionJob`. The two fire at the
         * same instant at the end of the join window, both read `status = live`,
         * and both close: `SendSessionReport` stamps `report_sent_at` AFTER it
         * dispatches, so a parent gets two reports for one hour.
         *
         * The claim is both the check and the write. Never `lockForUpdate()`,
         * which is a no-op on SQLite and would make a test pass here while
         * proving nothing about the MySQL this ships to.
         */
        $claimed = ClassSession::query()
            ->whereKey($session->getKey())
            ->whereNull('broadcast_room_id')
            ->update([
                // Stored, never sent: naming the provider in a payload is naming
                // it in the frontend bundle (FR-019).
                'broadcast_provider' => $this->provider->identifier(),
                'broadcast_room_id' => $room->providerRoomId,
                'room_opened_at' => now(),
                'status' => ClassSessionStatus::Live->value,
            ]);

        $session->refresh();

        // The loser returns the room the winner opened — the same answer, and no
        // second timeline. Only the runner that actually claimed it schedules.
        if ($claimed === 0) {
            return $session;
        }

        // Both timing rules of the phase, dispatched once, at the only moment
        // that knows the session actually started.
        //
        // Absent is written AT the threshold, not swept up later (FR-021ب ·
        // SC-021), and the close runs at the scheduled end so a teacher who just
        // shuts their laptop does not leave a session live forever.
        MarkAbsenteesJob::dispatch((int) $session->getKey())
            ->delay($session->absenceThresholdAt());

        CloseClassSessionJob::dispatch((int) $session->getKey())
            ->delay($session->ends_at->copy()->addMinutes($this->settings->joinWindowMinutes()));

        return $session;
    }
}
