<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
| Constitution I, the queue clause — for the module that mints credits.
|
| WorkspaceContext is an application-wide singleton that caches its resolution. A
| queue worker is one long-lived process handling many workspaces' work, so a
| set() inside it is not a scoping decision but a permanent change to everything
| that worker touches afterwards. The precedent is
| TrustScoreJobIsolationTest, which scans `Modules/Marketplace/Jobs/`.
|
| ⚠️ This one scans LISTENERS as well, and that is the whole difference. Credits
| are minted by a queued LISTENER — PaymentApproved arrives, credits are written
| — and a queued listener is a worker context in exactly the way a job is. A scan
| of Jobs/ alone would read as covered while the one class that matters sat
| outside it.
*/

/** @return list<string> */
function billingWorkerFiles(): array
{
    $directories = array_values(array_filter(
        [app_path('Modules/Payments/Jobs'), app_path('Modules/Payments/Listeners')],
        'is_dir',
    ));

    if ($directories === []) {
        return [];
    }

    return array_map(
        fn (SplFileInfo $file): string => $file->getPathname(),
        array_values(iterator_to_array(Finder::create()->files()->in($directories)->name('*.php'))),
    );
}

/**
 * One file's source with every comment removed.
 *
 * The scan reads CODE, not prose. Without this the guard fires on the docblock
 * that explains the rule — so the only way to keep it green would be to stop
 * writing down why the rule exists, which is the opposite of what it is for.
 */
function billingCodeWithoutComments(string $file): string
{
    $kept = [];

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $kept[] = is_array($token) ? $token[1] : $token;
    }

    return implode('', $kept);
}

it('contains no WorkspaceContext::set call in any billing job or listener', function (): void {
    $files = billingWorkerFiles();

    // A scan that found nothing would pass by finding nothing — the failure mode
    // of every source-reading guard. There is at least one queued listener in
    // this module today and there will be more.
    expect($files)->not->toBeEmpty();

    $offenders = [];

    foreach ($files as $file) {
        $contents = billingCodeWithoutComments($file);

        if (str_contains($contents, 'WorkspaceContext::set')) {
            $offenders[] = basename($file).' → WorkspaceContext::set';
        }

        // Any variable holding the context, not just one spelled `$context`: the
        // banned call is `->set(` on it, and naming the variable differently is
        // not a different decision.
        if (str_contains($contents, 'WorkspaceContext') && preg_match('/->set\(/', $contents) === 1) {
            $offenders[] = basename($file).' → ->set( on the workspace context';
        }
    }

    expect($offenders)->toBe([]);
});

it('covers the listeners directory, not the jobs directory alone', function (): void {
    // Stated as its own case because the omission it guards against is silent:
    // a later refactor that narrowed the scan back to Jobs/ would leave the test
    // green and the minting path unwatched.
    $scanned = array_map('basename', billingWorkerFiles());

    expect($scanned)->toContain('CreateEnrollmentFromOrder.php', 'CreditPurchaseOnApproval.php');
});

it('strips comments without stripping code', function (): void {
    // The stripper is what stands between "the rule is documented" and "the
    // guard fires on its own documentation". A stripper that returned an empty
    // string would make the scan above pass over everything, so both halves are
    // asserted: the phrase is present in the prose, absent from the code, and
    // the code itself survives.
    $file = app_path('Modules/Payments/Listeners/CreditPurchaseOnApproval.php');

    expect((string) file_get_contents($file))->toContain('WorkspaceContext::set')
        ->and(billingCodeWithoutComments($file))
        ->not->toContain('WorkspaceContext::set')
        ->toContain('class CreditPurchaseOnApproval');
});
