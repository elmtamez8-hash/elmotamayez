<?php

declare(strict_types=1);

use App\Modules\Marketplace\Actions\RecalculateTrustScore;
use App\Modules\Marketplace\Jobs\RecalculateTrustScoreJob;
use App\Shared\Support\WorkspaceContext;

/**
 * Constitution I, the queue clause.
 *
 * WorkspaceContext is an application-wide singleton that caches its resolution.
 * A queue worker is one long-lived process handling many workspaces' jobs, so a
 * set() inside a job is not a scoping decision — it is a permanent change to
 * every job that worker touches afterwards. This suite is the tripwire.
 */
it('leaves the surrounding workspace context untouched', function (): void {
    $academy = marketplaceWorkspace('Academy A');
    $other = marketplaceWorkspace('Academy B');

    $teacher = marketplaceTeacher($other, [
        'completed_sessions_count' => 12,
        'attendance_rate' => 90,
        'first_session_at' => now()->subYears(2),
    ]);

    $context = app(WorkspaceContext::class);
    $context->set($academy);

    app(RecalculateTrustScoreJob::class, ['teacherProfileId' => $teacher->getKey()])
        ->handle($context, app(RecalculateTrustScore::class));

    // The job ran against Academy B's teacher and gave the worker back to Academy A.
    expect($context->id())->toBe($academy->getKey())
        ->and($teacher->fresh()?->trust_score_calculated_at)->not->toBeNull();
});

it('restores a null context after running', function (): void {
    $workspace = marketplaceWorkspace();
    $teacher = marketplaceTeacher($workspace);

    // A worker that has not handled anything yet resolves to null — the state a
    // leak is easiest to spot in, and the one a public request depends on.
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
    $context = app(WorkspaceContext::class);

    expect($context->id())->toBeNull();

    app(RecalculateTrustScoreJob::class, ['teacherProfileId' => $teacher->getKey()])
        ->handle($context, app(RecalculateTrustScore::class));

    expect($context->id())->toBeNull();
});

// The behavioural tests above cannot see a set() that happens to be balanced by
// luck; this one reads the source, which is what the quickstart tells a reviewer
// to do by hand.
it('contains no WorkspaceContext::set call in any module Jobs directory', function (): void {
    /*
    | ⚠️ A PATTERN, NEVER A HAND-WRITTEN LIST OF MODULES. This check guarded
    | `Modules/Marketplace/Jobs/` alone for four specs while every other module
    | grew queued jobs of its own — a guard that covers the one place the defect
    | was already found is a guard that cannot find the next one. Spec 011's
    | rollup walks every workspace on the platform in a loop, which is the
    | sharpest possible shape for this leak, and it was outside the old glob.
    |
    | The failure message names the FILE, because a bare boolean over forty
    | directories is a red build with nowhere to look.
    */
    $files = glob(app_path('Modules/*/Jobs/*.php')) ?: [];

    // A pattern that matched nothing would make every assertion below vacuous.
    expect(count($files))->toBeGreaterThan(10);

    $offences = [];

    foreach ($files as $file) {
        /*
        | ⚠️ COMMENTS STRIPPED FIRST, AND THE GUARD USED TO FAIL ON ITS OWN
        | WARNINGS. Four jobs across three modules carry a line saying «NEVER
        | WorkspaceContext::set()» — documenting the very absence this test
        | exists to prove — and a raw `str_contains` read each of them as an
        | offence. A guard that goes red when somebody writes down why the rule
        | matters teaches people to delete the explanation. Same stripper, same
        | reason, as `ContextIsolationTest`.
        */
        $source = stripPhpComments((string) file_get_contents($file));

        if (str_contains($source, '$context->set(') || str_contains($source, 'WorkspaceContext::set')) {
            $offences[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }
    }

    expect($offences)->toBe([]);
});

/**
 * Source with every comment removed, so a rule written down beside the code it
 * governs is not read as a breach of itself.
 */
function stripPhpComments(string $source): string
{
    $kept = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $kept[] = is_array($token) ? $token[1] : $token;
    }

    return implode('', $kept);
}
