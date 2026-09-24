<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\SaveRubric;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Support\GradingSettings;
use App\Modules\Marketplace\Models\Subject;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| The four teacher screens that had an endpoint and no caller: the reads their
| editors need, and the one write that used to lose data on the way.
*/

it('carries the rubric on the single-question read, in order', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $essay = bankQuestion($workspace, null, ['type' => 'essay', 'points' => 5, 'content' => 'اشرح.']);

    app(SaveRubric::class)->handle($essay, [
        ['label' => 'المحتوى', 'max_points' => 3, 'order' => 0],
        ['label' => 'اللغة', 'max_points' => 2, 'order' => 1],
    ]);

    Sanctum::actingAs($owner);

    $response = $this->getJson("/api/v1/manage/bank/questions/{$essay->uuid}")->assertOk();

    expect(array_column($response->json('data.rubric_criteria'), 'label'))->toBe(['المحتوى', 'اللغة'])
        ->and($response->json('data.rubric_criteria.0.max_points'))->toEqual(3);
});

it('answers an empty rubric rather than no key for an essay without one', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $essay = bankQuestion($workspace, null, ['type' => 'essay', 'points' => 5, 'content' => 'اشرح.']);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/manage/bank/questions/{$essay->uuid}")
        ->assertOk()
        ->assertJsonPath('data.rubric_criteria', []);
});

it('refuses an overflowing rubric with a sentence the screen can print', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $essay = bankQuestion($workspace, null, ['type' => 'essay', 'points' => 5, 'content' => 'اشرح.']);

    Sanctum::actingAs($owner);

    // `userMessage()` echoes a 422's message verbatim, so an English one here is
    // English on the teacher's screen.
    $message = $this->putJson("/api/v1/manage/bank/questions/{$essay->uuid}/rubric", [
        'criteria' => [
            ['label' => 'المحتوى', 'max_points' => 4],
            ['label' => 'اللغة', 'max_points' => 4],
        ],
    ])->assertStatus(422)->json('message');

    expect($message)->toBe('مجموع درجات المعايير أكبر من درجة السؤال.');
});

it('tells the grading board which way anonymity stands, even with nothing queued', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/manage/grading/queue')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.anonymous', false);

    app(GradingSettings::class)->setAnonymous($workspace, true);

    $this->getJson('/api/v1/manage/grading/queue')->assertJsonPath('meta.anonymous', true);
});

it('keeps a concept\'s subject when only its name is changed', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $subject = Subject::factory()->create();
    $concept = Concept::create(['workspace_id' => $workspace->getKey(), 'name' => 'الجبر']);
    DB::table('concepts')->where('id', $concept->getKey())->update(['subject_id' => $subject->getKey()]);

    Sanctum::actingAs($owner);

    $this->patchJson("/api/v1/manage/bank/concepts/{$concept->uuid}", ['name' => 'الجبر الخطّي'])
        ->assertOk()
        ->assertJsonPath('data.name', 'الجبر الخطّي');

    expect(DB::table('concepts')->where('id', $concept->getKey())->value('subject_id'))
        ->toBe($subject->getKey());
});
