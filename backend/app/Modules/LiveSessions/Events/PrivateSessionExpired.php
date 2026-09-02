<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Nobody answered in time, or the teacher left the platform (FR-023 · FR-026).
 *
 * One event for both because the student's question is the same one — «هل من
 * ردّ؟» — and the answer is «لا، وليس هناك من يردّ». The reason is on the row.
 */
class PrivateSessionExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PrivateSessionRequest $request) {}
}
