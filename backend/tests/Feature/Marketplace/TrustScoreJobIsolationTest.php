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
it('contains no WorkspaceContext::set call anywhere in the Jobs directory', function (): void {
    $files = glob(app_path('Modules/Marketplace/Jobs/*.php')) ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect((string) file_get_contents($file))
            ->not->toContain('$context->set(')
            ->not->toContain('WorkspaceContext::set');
    }
});
