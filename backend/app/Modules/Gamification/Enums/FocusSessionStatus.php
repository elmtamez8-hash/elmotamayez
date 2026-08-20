<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Enums;

/**
 * ⚠️ WHICH OF THESE A FINISHED SESSION GETS IS THE SERVER'S DECISION, taken from
 * `now() - started_at` and never from anything the client sends — otherwise a
 * "120 minute" session completes in five seconds (FR-040).
 */
enum FocusSessionStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Interrupted = 'interrupted';
}
