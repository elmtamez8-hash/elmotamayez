<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

/**
 * What an import does with a row that already exists in the bank.
 *
 * ⚠️ Chosen AT UPLOAD, never asked about later. The import runs in a queued job
 * (FR-008) and by then the teacher has closed the tab — a job that stops to ask
 * a question is a job that hangs for ever. The report names every skipped row by
 * its line number instead, which is the answer they would have given anyway.
 *
 * `Skip` is decided by the database, not by a lookup: `unique(workspace_id,
 * content_hash)` makes it a constraint. Reading first and then inserting is the
 * definition of the race two browser tabs win together.
 */
enum DuplicatePolicy: string
{
    case Skip = 'skip';
    case Create = 'create';
}
