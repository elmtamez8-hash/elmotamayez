<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| A REAL student can sit a teacher's exam and hand it in.
|
| ⚠️ THIS COULD NOT BE MEASURED BY ANY EXISTING TEST, AND THAT IS THE WHOLE STORY.
| `SubmitAttemptRequest::authorize()` asked `can(ATTEMPTS_SUBMIT)`; spatie runs in
| TEAM MODE, and a real student is a member of no workspace — nothing on their
| path writes `users.last_workspace_id` — so the team id is null, they hold no
| role, and every `can()` for them is false. `POST /attempts/{uuid}/submit`
| answered **403 to every student on the platform**, while `StartAttempt` had
| already spent one of their `max_attempts` on the paper.
|
| Every test that covered this route used `addWorkspaceMember()` or
| `setCurrentWorkspace()` — both of which stamp the column and hand the student a
| context the product never gives them. The suite was green because it was
| measuring a person who does not exist. Found on 2026-08-30 while building spec
| 012, by walking the same door the adaptive path has to be guarded against.
|
| ⚠️ THE FIXTURE HERE MUST STAY MEMBERSHIP-FREE. Add a `setCurrentWorkspace()` for
| convenience and this file stops testing anything.
*/

it('lets an enrolled student with no workspace membership submit a real exam', function (): void {
    $fx = adaptiveFixture(['easy', 'easy']);
    $question = $fx['questions']->first();

    $exam = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $question): Exam {
        $exam = Exam::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'status' => 'published',
            'passing_score' => 50,
            'max_attempts' => 3,
        ]);

        ExamItem::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'exam_id' => $exam->getKey(),
            'question_id' => $question->getKey(),
            'order' => 1,
        ]);

        return $exam;
    });

    // The person the product actually creates: enrolled, member of nothing.
    expect($fx['student']->fresh()->last_workspace_id)->toBeNull();

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $uuid = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")
        ->assertCreated()
        ->json('attempt.uuid');

    $this->postJson("/api/v1/attempts/{$uuid}/submit", [
        'answers' => [[
            'question_id' => (int) $question->getKey(),
            'selected_option_ids' => [adaptiveRightOption((int) $question->getKey())],
        ]],
    ])->assertOk();

    $attempt = Attempt::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();

    // Marked, not merely accepted: an endpoint that answered 200 and wrote
    // nothing would satisfy a status assertion on its own.
    expect($attempt->status)->toBe(Attempt::STATUS_GRADED)
        ->and((float) $attempt->score)->toBe(100.0)
        ->and($attempt->submitted_at)->not->toBeNull();
});

/*
| The other half of the guard, which was never the problem: somebody else's paper
| is still refused. The permission check was hiding a policy that already worked.
*/
it('still refuses one student the attempt of another', function (): void {
    $fx = adaptiveFixture(['easy', 'easy']);
    $other = adaptiveFixture(['easy', 'easy']);
    $question = $fx['questions']->first();

    $exam = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $question): Exam {
        $exam = Exam::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'status' => 'published',
            'max_attempts' => 3,
        ]);

        ExamItem::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'exam_id' => $exam->getKey(),
            'question_id' => $question->getKey(),
            'order' => 1,
        ]);

        return $exam;
    });

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $uuid = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")
        ->assertCreated()
        ->json('attempt.uuid');

    Sanctum::actingAs($other['student']);
    $this->asGuest();

    $this->postJson("/api/v1/attempts/{$uuid}/submit", [
        'answers' => [[
            'question_id' => (int) $question->getKey(),
            'selected_option_ids' => [adaptiveRightOption((int) $question->getKey())],
        ]],
    ])->assertForbidden();

    expect(Attempt::query()->withoutWorkspaceScope()->where('uuid', $uuid)->value('status'))
        ->toBe(Attempt::STATUS_IN_PROGRESS);
});
