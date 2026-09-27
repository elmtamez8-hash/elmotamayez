<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Enums\ExamStatus;
use App\Modules\Assessments\Models\Exam;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;

/**
 * Move an exam between draft, published and archived — the one door every
 * status change from the panel goes through. The twin of `ChangeCourseStatus`.
 *
 * ⚠️ THE PANEL WROTE THE COLUMN RAW. `/admin`'s exam form carried a `status`
 * select saved by Filament's default `$record->update($data)`, so a paper
 * published from the panel left no `published` entry in the activity log while
 * the same act through the API (`PublishExam`) did — and an unpublish or an
 * archive left no trace at all.
 *
 * Publishing is delegated to `PublishExam` rather than restated, so the API's
 * route and the panel cannot drift into two spellings of one act.
 */
class ChangeExamStatus extends Action
{
    use LogsActivity;

    public function __construct(private readonly PublishExam $publish) {}

    public function handle(Exam $exam, ExamStatus $to): Exam
    {
        $from = $exam->status;

        if ($from === $to->value) {
            return $exam;
        }

        if ($to === ExamStatus::Published) {
            return $this->publish->handle($exam);
        }

        $exam->update(['status' => $to->value]);

        $this->logActivity($to === ExamStatus::Archived ? 'archived' : 'unpublished', $exam, [
            'from' => $from,
            'to' => $to->value,
        ]);

        return $exam->refresh();
    }
}
