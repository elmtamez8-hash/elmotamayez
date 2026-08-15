<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\ImportQuestions;
use App\Modules\Assessments\Enums\ImportStatus;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use Illuminate\Support\Facades\Storage;

/*
| FR-007 · SC-004. A thousand rows, ten of them broken.
|
| ⚠️ THE ASSERTION THAT MATTERS IS NOT THE COUNT OF IMPORTED QUESTIONS. A test
| that uploads a clean file and counts rows passes against an importer that
| aborts the batch on the first bad line, because a clean file has no bad line.
| Every case here puts broken rows IN, and asserts on what the report says about
| them by line number — which is the half the teacher cannot reconstruct.
*/

it('imports a thousand rows, names the ten that failed, and keeps the rest', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $body = '';
    $brokenLines = [];

    for ($i = 1; $i <= 1000; $i++) {
        if ($i % 100 === 0) {
            // Ten rows with an unknown difficulty. Line number is i + 1 because
            // the header is line 1.
            $brokenLines[] = $i + 1;
            $body .= "\"سؤال معطوب {$i}؟\",الجبر,impossible,remember,mcq,1,,أ|ب,1\n";

            continue;
        }

        $body .= mcqRow("سؤال سليم {$i}؟");
    }

    $import = startImport((int) $workspace->id, (int) $owner->id, importFile($body));

    app(ImportQuestions::class)->handle($import);

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->total_rows)->toBe(1000)
        ->and($import->imported_count)->toBe(990)
        ->and($import->failed_count)->toBe(10)
        // The batch did not fall for one row.
        ->and(Question::where('workspace_id', $workspace->id)->count())->toBe(990);

    $reported = collect($import->report)->pluck('line')->all();

    expect($reported)->toBe($brokenLines);

    // ⚠️ AND EACH ONE SAYS WHY. A report that counts failures without naming them
    // sends the teacher back to a thousand-row spreadsheet with no line to open.
    expect($import->report[0]['reason'])->toContain('impossible')
        ->and($import->report[0]['content'])->toContain('سؤال معطوب 100');
});

it('reads the first column of a file Excel exported with a byte-order mark', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $import = startImport(
        (int) $workspace->id,
        (int) $owner->id,
        importFile(mcqRow('سؤالٌ من إكسل؟'), withBom: true),
    );

    app(ImportQuestions::class)->handle($import);

    /*
     | ⚠️ WITHOUT THE STRIP THIS FILE IMPORTS ZERO ROWS AND LOOKS FINE IN EVERY
     | EDITOR. The three bytes sit in front of the first header cell, so `content`
     | arrives as "\xEF\xBB\xBFcontent" and matches nothing — and since `content`
     | is the required column, every row of every file exported from Excel fails
     | with "نصّ السؤال فارغ". This is the whole reason the header is normalised
     | rather than compared as read.
     */
    expect($import->refresh()->status)->toBe(ImportStatus::Done)
        ->and($import->imported_count)->toBe(1)
        ->and(Question::where('content', 'سؤالٌ من إكسل؟')->exists())->toBeTrue();
});

it('creates the concepts a file names and reuses them across its rows', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $before = Concept::where('workspace_id', $workspace->id)->count();

    $body = mcqRow('س١؟', 'المشتقّات').mcqRow('س٢؟', 'المشتقّات').mcqRow('س٣؟', 'التكامل');

    $import = startImport((int) $workspace->id, (int) $owner->id, importFile($body));

    app(ImportQuestions::class)->handle($import);

    // Two new concepts for three rows — not three, and not a failure per row.
    expect(Concept::where('workspace_id', $workspace->id)->count())->toBe($before + 2)
        ->and($import->refresh()->imported_count)->toBe(3);
});

it('refuses a row with no concept and one with no correct option', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $body = "\"بلا فكرة؟\",,easy,remember,mcq,1,,أ|ب,1\n"
        ."\"بلا إجابة صحيحة؟\",الجبر,easy,remember,mcq,1,,أ|ب,\n";

    $import = startImport((int) $workspace->id, (int) $owner->id, importFile($body));

    app(ImportQuestions::class)->handle($import);

    $import->refresh();

    expect($import->failed_count)->toBe(2)
        ->and($import->imported_count)->toBe(0)
        ->and($import->report[0]['reason'])->toContain('الفكرة')
        // A question nobody can get right is not a hard question. Its 100% wrong
        // rate would read as the hardest item in the teacher's bank.
        ->and($import->report[1]['reason'])->toContain('correct');
});

it('marks the correct option by its position, not by repeating its text', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $body = "\"أيّها الصحيح؟\",الجبر,medium,apply,mcq,2,,أ|ب|ج,2\n";

    $import = startImport((int) $workspace->id, (int) $owner->id, importFile($body));

    app(ImportQuestions::class)->handle($import);

    $question = Question::where('content', 'أيّها الصحيح؟')->firstOrFail();

    expect($import->refresh()->imported_count)->toBe(1)
        ->and($question->points)->toBe(2)
        ->and($question->options()->count())->toBe(3)
        ->and(QuestionOption::where('question_id', $question->id)->where('is_correct', true)->pluck('content')->all())
        ->toBe(['ب']);
});

it('fails the whole import, with a reason, when the required columns are missing', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    Storage::fake('local');
    Storage::disk(config('filesystems.default'))->put('imports/wrong.csv', "name,age\nأحمد,12\n");

    $import = startImport((int) $workspace->id, (int) $owner->id, 'imports/wrong.csv');

    app(ImportQuestions::class)->handle($import);

    // A file that is not a question file at all produces no rows to report, so
    // the reason belongs to the import — not to a line nobody can find.
    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_reason)->toContain('content')
        ->and($import->report)->toBeNull();
});
