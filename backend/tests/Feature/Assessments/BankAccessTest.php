<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\SyncExamItems;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Support\BankSearch;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| NFR-007. Whose bank is it.
|
| ⚠️ THE SEARCH HALF IS ASSERTED ON THE QUERY, NOT ON RESULTS. The suite runs
| `SCOUT_DRIVER=null`, so the engine returns nothing whatever the constraints
| say — an assertion on the rows would pass against a builder carrying no tenant
| filter at all, which is the exact bug it claims to rule out. The workspace
| guard is read off the builder.
*/

it('does not show one teacher the questions of another', function (): void {
    [$mine, $me] = $this->createWorkspaceWithOwner();
    [$theirs, $them] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($theirs, $them);
    bankQuestion($theirs, null, ['content' => 'سؤالٌ لا يخصّني؟']);

    $this->setCurrentWorkspace($mine, $me);
    bankQuestion($mine, null, ['content' => 'سؤالي؟']);

    Sanctum::actingAs($me);

    $response = $this->getJson('/api/v1/manage/bank/questions');

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('content')->all())->toBe(['سؤالي؟']);
});

it('constrains the search engine by workspace on the builder itself', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $builder = app(BankSearch::class)->scout('نيوتن');

    // Scout stores constraints as a LIST of field/operator/value triples, not as
    // a keyed map — asserting `toHaveKey('workspace_id')` on it passes vacuously
    // for a builder with no constraints at all.
    $fields = array_column($builder->wheres, 'value', 'field');

    expect($fields)->toHaveKey('workspace_id')
        ->and($fields['workspace_id'])->toBe(app(WorkspaceContext::class)->id())
        // And the filter columns go to the engine too. A filter applied to the
        // rows it returns arrives after another teacher's question has already
        // consumed a result slot and counted toward the total.
        ->and($fields)->toHaveKey('is_active');
});

it('refuses to put another teacher’s question into an exam', function (): void {
    [$mine, $me] = $this->createWorkspaceWithOwner();
    [$theirs, $them] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($theirs, $them);
    $notMine = bankQuestion($theirs, null, ['content' => 'سؤالهم؟']);

    $this->setCurrentWorkspace($mine, $me);
    $exam = Exam::create([
        'workspace_id' => $mine->id, 'uuid' => Str::uuid(),
        'title' => 'اختباري', 'max_attempts' => 3, 'status' => 'published',
    ]);

    // ⚠️ THE UUID IS A REAL ONE. Passing a made-up uuid would prove only that the
    // Action rejects nonsense; passing a uuid that EXISTS in another workspace is
    // the actual attack, and the only thing standing between it and an exam is
    // the workspace clause in the resolver.
    expect(fn () => app(SyncExamItems::class)->handle($exam, [['uuid' => $notMine->uuid]]))
        ->toThrow(DomainException::class);
});

it('lets an assistant browse the bank and refuses them authorship', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $concept = bankQuestion($workspace, null, ['content' => 'سؤالٌ قائم؟'])->concept;

    $assistant = $this->addWorkspaceMember($workspace, 'assistant-teacher');

    Sanctum::actingAs($assistant);

    /*
     | ⚠️ SPEC 008 TOOK `questions.manage` OFF THIS ROLE AND LEFT `bank.view`.
     | The permission used to authorise editing questions inside one exam; after
     | 008 the same name governs a library shared by every exam in the workspace.
     | Widening what a permission means while leaving its holders alone is how an
     | assistant silently inherits the teacher's whole bank.
     */
    expect($assistant->can(Permissions::BANK_VIEW))->toBeTrue()
        ->and($assistant->can(Permissions::QUESTIONS_MANAGE))->toBeFalse();

    $this->getJson('/api/v1/manage/bank/questions')->assertOk();

    $this->postJson('/api/v1/manage/bank/questions', [
        'concept_id' => $concept->uuid,
        'type' => 'mcq',
        'difficulty' => 'easy',
        'bloom_level' => 'remember',
        'content' => 'سؤالٌ من المساعد؟',
        'points' => 1,
        'options' => [
            ['content' => 'أ', 'is_correct' => true],
            ['content' => 'ب', 'is_correct' => false],
        ],
    ])->assertForbidden();
});

it('refuses a student the bank entirely', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, 'student');

    Sanctum::actingAs($student);

    // The bank carries `is_correct` on every option and the explanation. It is
    // the answer key, and a student reading it is every exam in the workspace
    // answered in advance.
    $this->getJson('/api/v1/manage/bank/questions')->assertForbidden();
});
