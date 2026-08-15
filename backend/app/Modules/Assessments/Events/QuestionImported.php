<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

use App\Modules\Assessments\Models\QuestionImport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An import finished — whatever the outcome of its rows.
 *
 * Fired once per file, not once per question. A thousand-row upload firing a
 * thousand events would produce a thousand notifications for one action the
 * teacher took, and the mistake notebook and the analytics rollups both read the
 * table rather than counting events.
 *
 * It carries the whole import rather than a count, because the only thing worth
 * telling the teacher is where to find the report.
 */
class QuestionImported
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly QuestionImport $import,
    ) {}
}
