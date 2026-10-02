<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Enums;

/**
 * uploading → queued → converting → done, and any of the first three → failed.
 * Every move is a conditional UPDATE on the current value; nothing else moves it.
 */
enum BoardImportStatus: string
{
    case Uploading = 'uploading';
    case Queued = 'queued';
    case Converting = 'converting';
    case Done = 'done';
    case Failed = 'failed';
}
