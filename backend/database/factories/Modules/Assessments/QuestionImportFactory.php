<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Assessments\Enums\ImportStatus;
use App\Modules\Assessments\Models\QuestionImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionImport>
 */
class QuestionImportFactory extends Factory
{
    protected $model = QuestionImport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'uploaded_by' => 1,
            'original_filename' => 'questions.csv',
            'stored_path' => 'imports/questions.csv',
            'duplicate_policy' => DuplicatePolicy::Skip,
            'status' => ImportStatus::Queued,
            'total_rows' => 0,
            'imported_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'report' => null,
        ];
    }
}
