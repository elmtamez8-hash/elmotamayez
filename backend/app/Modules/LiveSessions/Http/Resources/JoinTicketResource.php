<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Data\JoinTicket;
use App\Modules\LiveSessions\Support\SessionSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JoinTicket
 *
 * The only thing that crosses into the browser. It carries no provider name, no
 * key and no secret (FR-019) — and it expires, so a leaked one dies on its own
 * and no ticket opens a room that has been closed.
 *
 * The heartbeat interval rides along because the client cannot invent it: the
 * server's crediting cap is two intervals, and a page pinging on its own guess
 * would either waste requests or lose time it actually spent.
 */
class JoinTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'room_url' => $this->roomUrl,
            'token' => $this->token,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'role' => $this->role,
            'presence_interval_seconds' => app(SessionSettings::class)->presenceIntervalSeconds(),
        ];
    }
}
