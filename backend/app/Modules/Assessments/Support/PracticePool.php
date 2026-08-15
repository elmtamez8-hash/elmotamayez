<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\Attempt;
use Illuminate\Support\Facades\DB;

/**
 * What a student may be practised on, and what must be held back (FR-022أ).
 *
 * ⚠️ THE EXAM THEY HAVE NOT SAT YET IS SUBTRACTED, AND THIS IS THE WHOLE REASON
 * THE CLASS EXISTS. One bank question serves several exams, so a question the
 * student got wrong last month can also be sitting in next week's paper. Practise
 * it and the platform marks it instantly and shows the explanation (FR-024) —
 * which is the exam, with its answers, handed over a week early.
 *
 * Shared rather than inlined in one action: "test me on my mistakes" and the
 * self-generated exam draw from the same bank and need the identical subtraction.
 * Two copies of this rule is one copy that will be forgotten when a third caller
 * appears.
 */
class PracticePool
{
    /**
     * Question ids this student must not be practised on right now.
     *
     * @return list<int>
     */
    public function withheldQuestionIds(int $workspaceId, int $studentId): array
    {
        /*
        | "Not completed" is the absence of a submitted attempt — not the absence
        | of a passing one. A student who sat the paper and failed has already
        | seen every question on it, so withholding them afterwards protects
        | nothing and blocks revision of exactly the paper they need.
        */
        $satExamIds = Attempt::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $studentId)
            ->where('is_practice', false)
            ->whereNotNull('exam_id')
            ->whereNotNull('submitted_at')
            ->pluck('exam_id')
            ->all();

        $rows = DB::table('exam_items as i')
            ->join('exams as e', 'e.id', '=', 'i.exam_id')
            ->where('i.workspace_id', $workspaceId)
            ->where('e.status', 'published')
            ->when($satExamIds !== [], fn ($query) => $query->whereNotIn('i.exam_id', $satExamIds))
            ->distinct()
            ->pluck('i.question_id');

        $ids = [];

        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
