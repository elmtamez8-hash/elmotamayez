<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Enums;

/**
 * Why an import failed — a code, turned into Arabic in the browser
 * (`lib/whiteboard/strings.ts`). `too_large` is not here: it is a 422 before
 * the upload ticket or at `complete`.
 */
enum BoardImportFailure: string
{
    case TooManyPages = 'too_many_pages';
    case Unsupported = 'unsupported';
    case Corrupt = 'corrupt';
    case Timeout = 'timeout';
    case BoardDeleted = 'board_deleted';
}
