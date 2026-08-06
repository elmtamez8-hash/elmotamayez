<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Jobs\SyncTeacherCountersJob;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;

/**
 * Constitution I, the queue clause — the same tripwire as
 * TrustScoreJobIsolationTest, for this module's four jobs.
 *
 * WorkspaceContext is an application-wide singleton that caches its resolution.
 * A worker is one long-lived process handling many workspaces, so a set() inside
 * a job is not a scoping decision: it is a permanent change to every job that
 * worker touches afterwards. Nothing else in the suite would notice.
 */
it('gives the worker back the workspace it borrowed', function (): void {
    [$academy, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$other, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $this->setCurrentWorkspace($other, $ownerB);
    $teacher = TeacherProfile::factory()->create(['user_id' => $ownerB->getKey()]);

    $context = app(WorkspaceContext::class);
    $context->set($academy);

    (new SyncTeacherCountersJob((int) $teacher->getKey()))->handle($context);

    expect($context->id())->toBe($academy->getKey());
});

it('restores a null context after running', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    $teacher = TeacherProfile::factory()->create(['user_id' => $owner->getKey()]);

    // A worker that has handled nothing yet resolves to null — the state a leak
    // shows up in fastest, and the one every public request depends on.
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
    $context = app(WorkspaceContext::class);

    expect($context->id())->toBeNull();

    (new SyncTeacherCountersJob((int) $teacher->getKey()))->handle($context);

    expect($context->id())->toBeNull();
});

// The behavioural tests above cannot see a set() that happens to be balanced by
// luck. This one reads the source, which is what the quickstart tells a reviewer
// to do by hand.
it('contains no WorkspaceContext::set call anywhere in the Jobs directory', function (): void {
    $files = glob(app_path('Modules/LiveSessions/Jobs/*.php')) ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect((string) file_get_contents($file))
            ->not->toContain('$context->set(')
            ->not->toContain('WorkspaceContext::set');
    }
});
