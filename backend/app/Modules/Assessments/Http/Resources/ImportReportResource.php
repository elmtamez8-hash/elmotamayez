<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\QuestionImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What happened to one upload, row by row.
 *
 * `stored_path` is deliberately absent: it is a path on our disk, and a client
 * that receives one is a client that will eventually be given a way to ask for it.
 *
 * @mixin QuestionImport
 */
class ImportReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'filename' => $this->original_filename,
            'duplicate_policy' => $this->duplicate_policy->value,
            'status' => $this->status->value,
            'total_rows' => $this->total_rows,
            'imported_count' => $this->imported_count,
            'skipped_count' => $this->skipped_count,
            'failed_count' => $this->failed_count,
            // Failures and skips only — the successes are the questions
            // themselves, and listing them would make the report of a
            // 10,000-row file larger than the bank it filled.
            'rows' => $this->report ?? [],
            'failure_reason' => $this->failure_reason,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }
}
