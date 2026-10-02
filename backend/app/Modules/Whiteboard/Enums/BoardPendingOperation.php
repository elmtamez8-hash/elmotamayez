<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Enums;

/**
 * A queued job owns the board while this is set; written by a conditional UPDATE
 * `WHERE pending_operation IS NULL`, so a second click finds it taken (409).
 * `building` and `deleting` hide the board from lists and from `view`.
 */
enum BoardPendingOperation: string
{
    case Building = 'building';
    case Duplicating = 'duplicating';
    case Deleting = 'deleting';
}
