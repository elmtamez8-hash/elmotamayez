<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Enums;

/**
 * The board's surface (FR-007). Each one has a high-contrast palette in the
 * browser (`lib/whiteboard/page-model.ts`), and switching recolours what is
 * already drawn (owner decision on phase 0).
 */
enum BoardBackground: string
{
    case White = 'white';
    case Blackboard = 'blackboard';
    case Greenboard = 'greenboard';
}
