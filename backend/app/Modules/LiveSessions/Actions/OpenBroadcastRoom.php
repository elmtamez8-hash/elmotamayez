<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
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

        return $session;
    }
}
