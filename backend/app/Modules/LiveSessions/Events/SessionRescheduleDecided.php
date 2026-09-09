<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The teacher answered — and an approval is news for the whole room.
 *
 * ⚠️ THE SEAT HOLDERS TRAVEL ON THE EVENT, and the reason is not the one
 * `SessionCancelled` has. Cancelling releases the seats, so a listener reading
 * them afterwards finds an undifferentiated pile; rescheduling releases nothing,
 * so the ids could in principle be re-read. They are carried anyway because the
 * listener must not have to know WHICH question to ask — a second spelling of
 * «who is in this lesson» beside `seatHolderUserIds()` is the two-answers defect
 * this tree has paid for repeatedly.
 *
 * ⚠️ AND THE OLD TIME TRAVELS TOO. By the time a listener runs the session row
 * already carries the NEW `starts_at`, so «من السبت ٤م إلى الأحد ٦م» is
 * unsayable without it.
 *
 * ⚠️ ONLY THE REQUESTER HEARS A REFUSAL. A rejection is an answer to one person;
 * telling nine classmates that somebody asked to move their lesson and was
 * refused is a conversation none of them were in.
 *
 * @param  list<int>  $seatHolderIds  empty on a rejection — nothing moved.
 */
class SessionRescheduleDecided
{
    use Dispatchable, SerializesModels;

    /** @param list<int> $seatHolderIds */
    public function __construct(
        public readonly SessionRescheduleRequest $request,
        public readonly bool $approved,
        public readonly array $seatHolderIds = [],
    ) {}
}
