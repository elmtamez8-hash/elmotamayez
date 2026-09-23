<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Support\PanelSessionActivity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an `/admin` sign-in's `last_active_at` moving while the panel is used,
 * so the nightly sweep can tell a live operator from a web session that expired.
 *
 * ⚠️ REGISTERED AS PERSISTENT in the panel's `authMiddleware`, so it also runs on
 * Livewire's update requests. An operator working a table for an hour on one page
 * makes no page loads at all — every click is a Livewire request — and each of
 * those keeps the web session alive. Touching on page loads alone would let the
 * sweep end (and destroy) a session somebody is using right now.
 */
class TouchPanelSession
{
    public function __construct(private readonly PanelSessionActivity $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->activity->touch($request);

        return $next($request);
    }
}
