<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;

class PublishExam extends Action
{
    use LogsActivity;

    public const EMPTY_REFUSAL = 'أضِف سؤالاً واحداً على الأقل قبل نشر الاختبار.';

    /**
     * @throws DomainException when the exam has no question yet
     */
    public function handle(Exam $exam): Exam
    {
        /*
        | ⛔ AN EXAM WITH NO QUESTION IS NOT PUBLISHED (QA 2026-10-29). It went
        | live empty: a student could start it, hand in nothing and be marked on
        | a paper of zero points. Asked here, the one door the API and the panel
        | (`ChangeExamStatus`) share.
        */
        if (! ExamItem::query()->withoutWorkspaceScope()->where('exam_id', $exam->getKey())->exists()) {
            throw new DomainException(self::EMPTY_REFUSAL);
        }

        $exam->update(['status' => 'published']);

        $this->logActivity('published', $exam);

        $exam->refresh();

        return $exam;
    }
}
