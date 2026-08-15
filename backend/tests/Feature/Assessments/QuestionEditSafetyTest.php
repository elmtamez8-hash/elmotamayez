<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\SaveQuestion;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use Illuminate\Support\Str;

/*
| FR-004 · SC-003 · SC-001. What a teacher may change, and what may never move.
|
| ⚠️ THE ASSERTION THAT MATTERS IS NOT THE SCORE. A test that edits a question
| and compares `exam_attempts.score` passes even when the review screen is
| broken, because the score column was never the thing at risk — it has been
| written at grading time since spec 003. What was stored NOWHERE was what the
| student saw, so an option deleted afterwards made their paper unrenderable.
| That is why every case here reads the snapshot back.
*/

function editSafetyExam(int $workspaceId): array
{
    $exam = Exam::create([
        'workspace_id' => $workspaceId, 'uuid' => Str::uuid(),
        'title' => 'اختبار التعديل', 'passing_score' => 50, 'max_attempts' => 3, 'status' => 'published',
    ]);

    $question = bankQuestion($exam->workspace, $exam, ['content' => 'النصّ الأصلي؟']);

    $right = QuestionOption::create([
        'workspace_id' => $workspaceId, 'question_id' => $question->id,
        'content' => 'الخيار الصحيح', 'is_correct' => true, 'order' => 1,
    ]);
    QuestionOption::create([
        'workspace_id' => $workspaceId, 'question_id' => $question->id,
        'content' => 'خيار سيُحذف', 'is_correct' => false, 'order' => 2,
    ]);

    return [$exam, $question, $right];
}

it('keeps a graded attempt intact when its question is rewritten and an option deleted', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, 'student');
    [$exam, $question, $right] = editSafetyExam((int) $workspace->id);

    $attempt = app(StartAttempt::class)->handle($exam, $student);
    app(GradeAttempt::class)->handle($attempt, [
        ['question_id' => $question->id, 'selected_option_ids' => [$right->id]],
    ]);

    $scoreBefore = $attempt->fresh()->score;

    // The teacher rewrites the text and drops the second option entirely.
    app(SaveQuestion::class)->update($question, ['content' => 'نصٌّ جديد تماماً؟'], [
        ['content' => 'الخيار الصحيح', 'is_correct' => true],
    ]);

    $attempt->refresh();
    $item = $attempt->items()->firstOrFail();

    expect($attempt->score)->toEqual($scoreBefore)
        // What they saw, unchanged — text and both options, including the one
        // that no longer exists anywhere else.
        ->and($item->snapshot['content'])->toBe('النصّ الأصلي؟')
        ->and($item->snapshot['options'])->toHaveCount(2)
        ->and(collect($item->snapshot['options'])->pluck('content')->all())
        ->toContain('خيار سيُحذف');
});

it('puts one bank question in three exams as one row', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace, null, ['content' => 'سؤالٌ مُعاد الاستعمال؟']);

    $exams = collect(range(1, 3))->map(fn (int $i) => Exam::create([
        'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
        'title' => "اختبار {$i}", 'status' => 'published',
    ]));

    $save = app(SaveQuestion::class);
    foreach ($exams as $exam) {
        $save->includeInExam($exam, $question);
    }

    // Worth more in the third than in the bank, without the bank row moving.
    ExamItem::query()
        ->where('exam_id', $exams[2]->id)
        ->where('question_id', $question->id)
        ->update(['points_override' => 5]);

    expect(Question::where('content', 'سؤالٌ مُعاد الاستعمال؟')->count())->toBe(1)
        ->and(ExamItem::where('question_id', $question->id)->count())->toBe(3)
        ->and($exams[0]->questions()->count())->toBe(1)
        ->and(
            ExamItem::where('exam_id', $exams[2]->id)->firstOrFail()->effectivePoints()
        )->toBe(5)
        ->and(
            ExamItem::where('exam_id', $exams[0]->id)->firstOrFail()->effectivePoints()
        )->toBe(1);
});

it('refuses a question missing any of its mandatory tags', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $save = app(SaveQuestion::class);

    // FR-002 · SC-002. Enforced in the Action, not only in a form request — the
    // importer and the seeder come through the same door and carry no request.
    foreach (['concept_id', 'difficulty', 'bloom_level'] as $missing) {
        $attributes = [
            'concept_id' => bankQuestion($workspace)->concept_id,
            'difficulty' => 'easy',
            'bloom_level' => 'remember',
            'type' => 'mcq',
            'content' => 'سؤالٌ ناقص؟',
            'points' => 1,
        ];
        unset($attributes[$missing]);

        expect(fn () => $save->createInBank((int) $workspace->id, $attributes))
            ->toThrow(DomainException::class);
    }
});
