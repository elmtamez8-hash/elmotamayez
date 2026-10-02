<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use App\Modules\Whiteboard\Enums\BoardImportFailure;
use RuntimeException;

/** An import that cannot finish, with the reason its row records (story 4). */
final class ImportFailed extends RuntimeException
{
    public function __construct(public readonly BoardImportFailure $reason)
    {
        parent::__construct($reason->value);
    }
}
