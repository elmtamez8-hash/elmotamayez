<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

/**
 * Where a question import has got to.
 *
 * ⚠️ The move from `Queued` to `Running` is an atomic conditional UPDATE, not a
 * plain write. Horizon retries a job that timed out mid-file, and a job that can
 * start twice re-imports the rows it already committed — the same read-then-write
 * that seat claiming, order capture and structure versioning all refuse.
 */
enum ImportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
