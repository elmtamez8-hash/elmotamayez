<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A student is waiting for an answer (FR-018). */
class PrivateSessionRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PrivateSessionRequest $request) {}
}
