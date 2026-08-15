<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\ImportQuestions;
use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Assessments\Enums\ImportStatus;
use App\Modules\Assessments\Jobs\ImportQuestionsJob;
use App\Modules\Assessments\Models\Question;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/*
| The same file twice, and the same job twice. Two different guards.
|
| ⚠️ ONE RUN PROVES NOTHING HERE. Every guard in this file only executes on the
| SECOND arrival, so a test that imports once passes for ever against no guard at
| all — the same blindness that let a double-submit write two answer sets from
| spec 003 until AttemptConcurrencyTest made the second call.
*/

it('does not create a second copy when the same file is uploaded twice with skip', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $body = mcqRow('سؤالٌ مكرَّر؟').mcqRow('سؤالٌ آخر؟');

    $first = startImport((int) $workspace->id, (int) $owner->id, importFile($body));
    app(ImportQuestionsJob::class, ['importId' => (int) $first->getKey()])->handle(
        app(WorkspaceContext::class),
        app(ImportQuestions::class),
    );

    $second = startImport((int) $workspace->id, (int) $owner->id, importFile($body));
    app(ImportQuestionsJob::class, ['importId' => (int) $second->getKey()])->handle(
        app(WorkspaceContext::class),
        app(ImportQuestions::class),
    );

    expect(Question::where('workspace_id', $workspace->id)->count())->toBe(2)
        ->and($second->refresh()->imported_count)->toBe(0)
        ->and($second->skipped_count)->toBe(2)
        // And it says which rows, by line — "0 imported" alone reads like a
        // failure to a teacher who cannot see why.
        ->and($second->report)->toHaveCount(2);
});

it('applies the duplicate policy within one file, not only against the table', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    // The same question three times in ONE upload — a real export from a system
    // that stored a question per exam produces exactly this.
    $body = mcqRow('نفس السؤال؟').mcqRow('نفس السؤال؟').mcqRow('نفس السؤال؟');

    $import = startImport((int) $workspace->id, (int) $owner->id, importFile($body));

    app(ImportQuestions::class)->handle($import);

    /*
     | ⚠️ WITHOUT THE IN-FILE CHECK ALL THREE INSERT. The table lookup finds
     | nothing for the first row and — because none of the three is committed
     | before the next is read in a way the lookup would notice at speed — the
     | teacher who chose "skip" gets the copies they asked us to prevent, from
     | the one file they uploaded to avoid them.
     */
    expect(Question::where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($import->refresh()->imported_count)->toBe(1)
        ->and($import->skipped_count)->toBe(2);
});

it('creates every copy when the policy says create', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $body = mcqRow('نفس السؤال؟').mcqRow('نفس السؤال؟');

    $import = startImport(
        (int) $workspace->id,
        (int) $owner->id,
        importFile($body),
        DuplicatePolicy::Create,
    );

    app(ImportQuestions::class)->handle($import);

    // The policy is a choice, and "create" has to actually create — a skip that
    // ignores the setting is the same bug wearing the other face.
    expect(Question::where('workspace_id', $workspace->id)->count())->toBe(2)
        ->and($import->refresh()->imported_count)->toBe(2)
        ->and($import->skipped_count)->toBe(0);
});

it('serialises imports per workspace, which is the whole duplicate guard', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $import = startImport((int) $workspace->id, (int) $owner->id, importFile(mcqRow('س؟')));

    /*
     | ⚠️ THIS ASSERTS ON A LINE OF CONFIGURATION, AND IT HAS TO.
     |
     | `DuplicatePolicy::Skip` is a lookup followed by an insert — the unique
     | index it was designed around could not be created on live data, which
     | already holds legitimate same-text questions. `WithoutOverlapping` is what
     | makes that lookup safe. Every other test in this file runs on the `sync`
     | driver, where queue middleware never executes at all, so deleting this line
     | would leave the entire suite green while "skip" quietly became
     | "sometimes skip" under two concurrent uploads.
     */
    $middleware = app(ImportQuestionsJob::class, ['importId' => (int) $import->getKey()])->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        // Keyed on the workspace, not on the import: two DIFFERENT uploads by one
        // teacher are exactly the race, so a key carrying the import id would
        // lock each job against only itself and guard nothing.
        ->and($middleware[0]->key)->toBe('question-import:'.$workspace->id);
});

it('does nothing when the job runs a second time on the same import', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $import = startImport(
        (int) $workspace->id,
        (int) $owner->id,
        importFile(mcqRow('س١؟').mcqRow('س٢؟').mcqRow('س٣؟')),
    );

    $context = app(WorkspaceContext::class);
    $action = app(ImportQuestions::class);

    app(ImportQuestionsJob::class, ['importId' => (int) $import->getKey()])->handle($context, $action);

    expect(Question::where('workspace_id', $workspace->id)->count())->toBe(3);

    /*
     | ⚠️ THIS IS THE HORIZON RETRY, AND ITS CORRECT BEHAVIOUR IS TO DO NOTHING.
     | A job that timed out halfway through a file is retried; a retry that ran
     | again would re-insert every question the first attempt committed, and the
     | teacher would find their bank doubled with no error anywhere. The claim is
     | one conditional UPDATE, so the second arrival loses it and returns.
     */
    app(ImportQuestionsJob::class, ['importId' => (int) $import->getKey()])->handle($context, $action);

    expect(Question::where('workspace_id', $workspace->id)->count())->toBe(3)
        ->and($import->refresh()->status)->toBe(ImportStatus::Done);
});
