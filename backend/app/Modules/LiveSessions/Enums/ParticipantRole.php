<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/**
 * What a person may do inside the room.
 *
 * Always derived from a permission on the server, never from anything the client
 * sends: a role in a request body is a request to be promoted.
 */
enum ParticipantRole: string
{
    case Host = 'host';
    case Participant = 'participant';
}
