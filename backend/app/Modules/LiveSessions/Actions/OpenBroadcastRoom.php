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

        $session->forceFill([
            // Stored, never sent: naming the provider in a payload is naming it
            // in the frontend bundle (FR-019).
            'broadcast_provider' => $this->provider->identifier(),
            'broadcast_room_id' => $room->providerRoomId,
            'room_opened_at' => now(),
            'status' => ClassSessionStatus::Live,
        ])->save();

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
