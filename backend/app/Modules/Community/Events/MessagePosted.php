<?php

declare(strict_types=1);

namespace App\Modules\Community\Events;

use App\Modules\Community\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was written.
 *
 * ⚠️ THE BROADCAST PAYLOAD IS TWO IDENTIFIERS AND NOTHING ELSE (`NFR-008`), and
 * that carries a DOUBLE LOAD. The first reason is the obvious one: a socket frame
 * is not an authorised response, and putting the body on the wire makes the
 * channel the access control for the words themselves.
 *
 * ⚠️ THE SECOND REASON IS REVOCATION, AND IT IS THE ONE A LATER READER WILL
 * «SIMPLIFY» AWAY. A channel is authorised ONCE, at subscribe, and the protocol
 * has no revocation call — so an assistant whose permission is withdrawn while
 * they are still connected keeps receiving every event on a channel they were
 * admitted to. With an identifier alone that is harmless: the fetch it provokes
 * goes through the authenticated route and is refused there, on the request after
 * the withdrawal, exactly as `SC-003` promises. Add the body to this payload and
 * the same withdrawal takes effect only when they close the tab.
 *
 * Two channels, because two screens listen: the open thread, and the list.
 */
class MessagePosted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /** @param  list<string>  $recipientUuids  everyone whose list should move */
    public function __construct(
        public readonly Message $message,
        public readonly string $conversationUuid,
        public readonly array $recipientUuids,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('conversation.'.$this->conversationUuid)];

        foreach ($this->recipientUuids as $uuid) {
            $channels[] = new PrivateChannel('user.'.$uuid);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.posted';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return [
            'message_uuid' => (string) $this->message->uuid,
            'conversation_uuid' => $this->conversationUuid,
        ];
    }
}
