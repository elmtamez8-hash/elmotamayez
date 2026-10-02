<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * The whiteboard's operational numbers: a `platform_settings` row first, then
 * `config/whiteboard.php`. Never below one — a zero ceiling would refuse every save.
 */
final class WhiteboardSettings
{
    public static function maxSceneBytes(): int
    {
        return max(1, (int) PlatformSettings::get('whiteboard.max_scene_bytes', config('whiteboard.max_scene_bytes')));
    }

    public static function maxBoardBytes(): int
    {
        return max(1, (int) PlatformSettings::get('whiteboard.max_board_bytes', config('whiteboard.max_board_bytes')));
    }

    public static function maxPagesPerBoard(): int
    {
        return max(1, (int) PlatformSettings::get('whiteboard.max_pages_per_board', config('whiteboard.max_pages_per_board')));
    }
}
