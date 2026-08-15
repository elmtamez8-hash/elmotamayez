<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\SaveQuestion;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Question;
use Laravel\Sanctum\Sanctum;

/*
| FR-002 · SC-002. Zero questions saved with a tag missing.
|
| ⚠️ THE ENFORCEMENT IS TESTED AT THE ACTION, NOT ONLY AT THE ENDPOINT. Three
| callers reach it — the API, the importer, and the seeder — and only one of them
| has a form request in front of it. A rule proved through the HTTP layer alone
| is a rule the importer does not have, and the importer is the one that writes a
| thousand rows at a time.
*/

it('refuses every question missing any one of its mandatory tags', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $save = app(SaveQuestion::class);
    $conceptId = bankQuestion($workspace)->concept_id;
    $before = Question::where('workspace_id', $workspace->id)->count();

    foreach (['concept_id', 'difficulty', 'bloom_level'] as $missing) {
        $attributes = [
            'concept_id' => $conceptId,
            'difficulty' => 'easy',
            'bloom_level' => 'remember',
            'type' => 'mcq',
            'content' => "سؤالٌ ينقصه {$missing}؟",
            'points' => 1,
        ];
        unset($attributes[$missing]);

        expect(fn () => $save->createInBank((int) $workspace->id, $attributes))
            ->toThrow(DomainException::class);
    }

    // Not one of the three got through — the count is the assertion, because a
    // throw that happens AFTER the insert would satisfy the expectation above.
    expect(Question::where('workspace_id', $workspace->id)->count())->toBe($before);
});

it('refuses an edit that blanks a tag the question already had', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace, null, ['content' => 'سؤالٌ موسوم؟']);
    $question->update(['difficulty' => 'hard']);

    /*
     | ⚠️ ONLY `concept_id` IS NOT NULL IN THE DATABASE. So a PATCH that empties
     | `difficulty` or `bloom_level` would be caught by nothing at all — the
     | column accepts '' and the question quietly leaves the tagged set that
     | spec 012's adaptive path selects on.
     */
    expect(fn () => app(SaveQuestion::class)->update($question, ['difficulty' => '']))
        ->toThrow(DomainException::class);

    expect($question->fresh()->difficulty)->toBe('hard');
});

it('leaves a tag alone when the payload does not mention it', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace, null, ['content' => 'نصٌّ قديم؟']);
    $question->update(['difficulty' => 'hard']);

    // Omitting a tag is not clearing it. Refusing the omission would make every
    // partial write impossible — including the importer's own `is_active` flip.
    app(SaveQuestion::class)->update($question, ['content' => 'نصٌّ جديد؟']);

    expect($question->fresh()->difficulty)->toBe('hard')
        ->and($question->fresh()->content)->toBe('نصٌّ جديد؟');
});

it('refuses an untagged question through the API too', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    Sanctum::actingAs($owner);

    $concept = Concept::where('workspace_id', $workspace->id)->first()
        ?? Concept::create(['workspace_id' => $workspace->id, 'name' => 'الجبر']);

    $this->postJson('/api/v1/manage/bank/questions', [
        'concept_id' => $concept->uuid,
        'type' => 'mcq',
        // bloom_level absent.
        'difficulty' => 'easy',
        'content' => 'سؤالٌ بلا مستوى معرفي؟',
        'points' => 1,
        'options' => [
            ['content' => 'أ', 'is_correct' => true],
            ['content' => 'ب', 'is_correct' => false],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['bloom_level']);

    expect(Question::where('content', 'سؤالٌ بلا مستوى معرفي؟')->exists())->toBeFalse();
});

it('refuses a multiple-choice question with no correct option', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    Sanctum::actingAs($owner);

    $concept = bankQuestion($workspace)->concept;

    /*
     | ⚠️ NOT A HARD QUESTION — A BROKEN ONE. Nothing downstream can tell the two
     | apart: the grader scores zero for everybody, the mistake notebook records
     | it against every student who sat it, and a wrong_pct of 100% reads as the
     | hardest item in the bank — which is the number a teacher deletes a good
     | question over.
     */
    $this->postJson('/api/v1/manage/bank/questions', [
        'concept_id' => $concept->uuid,
        'type' => 'mcq',
        'difficulty' => 'easy',
        'bloom_level' => 'remember',
        'content' => 'سؤالٌ بلا إجابةٍ صحيحة؟',
        'points' => 1,
        'options' => [
            ['content' => 'أ', 'is_correct' => false],
            ['content' => 'ب', 'is_correct' => false],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['options']);
});
