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
        /*
        | ⚠️ COMMENTS ARE STRIPPED FIRST, AND THAT IS NOT A CONVENIENCE. Jobs in
        | this directory carry a line SAYING «never `WorkspaceContext::set()`» —
        | which is the whole reason the absence is deliberate rather than
        | accidental. A raw `str_contains` turns that explanation into a red
        | build, and the cheapest way to make a red build green is to delete the
        | explanation. `TrustScoreJobIsolationTest` and `ContextIsolationTest`
        | had both already learned this; this file had not, and 027's seat job
        | is what found it.
        */
        expect(withoutPhpComments((string) file_get_contents($file)))
            ->not->toContain('$context->set(')
            ->not->toContain('WorkspaceContext::set');
    }
});

/**
 * The source with every comment and docblock removed, tokenised rather than
 * pattern-matched — a regex over PHP comments trips on the first `//` inside a
 * string literal.
 */
function withoutPhpComments(string $source): string
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
