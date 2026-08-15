<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Assessments\Enums\ImportStatus;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Assessments\QuestionImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One upload of questions, and what became of every row in it.
 *
 * @property ImportStatus $status
 * @property DuplicatePolicy $duplicate_policy
 * @property array<int, array{line: int, reason: string, content?: string}>|null $report
 */
class QuestionImport extends BaseModel
{
    /** @use HasFactory<QuestionImportFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'uploaded_by',
        'original_filename',
        'stored_path',
        'duplicate_policy',
        'status',
        'total_rows',
        'imported_count',
        'skipped_count',
        'failed_count',
        'report',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'duplicate_policy' => DuplicatePolicy::class,
            'report' => 'array',
            'total_rows' => 'integer',
            'imported_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Claims this import for a worker, once.
     *
     * ⚠️ ONE CONDITIONAL UPDATE, NOT A READ THEN A WRITE. Horizon retries a job
     * that timed out halfway through a file, and the retry arrives at a row that
     * is no longer `queued` — so it loses the claim and does nothing, rather than
     * re-importing the hundreds of questions the first run already committed.
     *
     * There is no resume: the partial import is reported as failed and the
     * teacher uploads again. Resuming would mean trusting a byte offset written
     * by a process that died, which is a harder promise than re-reading a file.
     */
    public function claim(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('status', ImportStatus::Queued->value)
            ->update([
                'status' => ImportStatus::Running->value,
                'started_at' => now(),
            ]) === 1;
    }
}
