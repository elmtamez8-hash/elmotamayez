<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\Exam;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;

class PublishExam extends Action
{
    use LogsActivity;

    public function handle(Exam $exam): Exam
    {
        $exam->update(['status' => 'published']);

        $this->logActivity('published', $exam);

        $exam->refresh();

        return $exam;
    }
}
