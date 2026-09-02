<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Exceptions;

use DomainException;

/**
 * The request has already moved on — somebody else got there first.
 *
 * ⚠️ A TYPE, NOT A MESSAGE. Withdrawing a request the teacher accepted a second
 * earlier is not «you may not»: the student holds every right to withdraw and
 * the row simply has a lesson standing behind it now. A 403 sends them to ask
 * for a permission they already have, and a 422 reads as bad input; a 409 tells
 * them to look at what actually happened. A controller cannot tell those apart
 * by reading a sentence.
 *
 * It extends `DomainException` so a caller that only knows the general case
 * still refuses cleanly — and the controller catches this one ABOVE that arm,
 * the ordering `BroadcastProviderUnavailable` needs for the same reason.
 */
class PrivateSessionConflictException extends DomainException {}
