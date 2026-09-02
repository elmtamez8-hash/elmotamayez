<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The teacher answered — either way (FR-018).
 *
 * ⚠️ THE REFUSAL IS THE HALF THE REQUIREMENT IS ABOUT. An acceptance announces
 * itself: the lesson appears in the student's timetable. A refusal that reaches
 * nobody is indistinguishable from a request still waiting, and is asked again
 * for ever — which is a queue the teacher then clears twice.
 *
 * ⚠️ AND IT IS DISPATCHED BEHIND THE CONDITIONAL UPDATE THAT SETTLED THE ROW.
 * Two teachers deciding one request on two screens must produce one message, not
 * an acceptance and a refusal about the same hour.
 */
class PrivateSessionDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PrivateSessionRequest $request,
        public readonly bool $accepted,
    ) {}
}
