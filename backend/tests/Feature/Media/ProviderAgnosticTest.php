<?php

declare(strict_types=1);

/*
| The rule that keeps MediaProviderInterface a contract rather than an intention.
|
| Same shape as ProviderAgnosticTest for notification channels: business logic
| may name a capability, never a vendor. Providers/ is the one place a vendor name
| belongs, by definition.
*/

/**
 * Block and line comments out, code in.
 *
 * ⚠️ WITHOUT THIS THE GUARD GOES RED OVER THE DOCBLOCK THAT EXPLAINS THE
 * CODE, and 032 proved it: `EmbedEditor` and `EmbeddedVideo` each open with one
 * sentence naming the two sites a teacher may embed from — «a lesson hosted at
 * YouTube or Vimeo» — and neither file picks a media provider anywhere in its
 * body. A red build over an explanation teaches people to delete the
 * explanation, which this repository has now paid for three times
 * (`TrustScoreJobIsolationTest`, `theme-tokens.test.ts`, and here).
 *
 * STRINGS ARE DELIBERATELY LEFT IN. A vendor's name in a string literal inside
 * business logic is exactly the offence this file exists to catch — a host
 * compared against, a bucket, a URL. Only the prose comes out.
 */
function stripComments(string $source): string
{
    return (string) preg_replace(['#/\*[\s\S]*?\*/#', '#(^|[^:])//.*$#m'], ['', '$1'], $source);
}

function scanForVendorNames(string $root, string $extensions): array
{
    $vendors = ['bunny', 'cloudflare', 'mux', 'vimeo', 'jwplayer', 'wistia'];
    $offenders = [];

    if (! is_dir($root)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if (! $file->isFile() || ! preg_match($extensions, $file->getFilename())) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        // The one sanctioned home for a vendor name.
        if (str_contains($path, '/Modules/Media/Providers/')) {
            continue;
        }

        // ⚠️ AND THE EMBED ALLOWLIST, WHICH ANSWERS A DIFFERENT QUESTION.
        // `videoEmbedUrl` turns a link the TEACHER typed into an iframe src on a
        // public page, against a CLOSED SET OF HOSTS — there the names are the
        // guard, and a vendor-agnostic version of it is an `<iframe src>` fed by
        // a free-text field. Vimeo is both an embeddable site and a hosting
        // vendor; only the second is this test's business, and nothing here
        // picks a media provider. (YouTube never fired only because it is not a
        // hosting vendor and so was never on the list.)
        if (str_contains($path, '/frontend/src/lib/video-embed.')) {
            continue;
        }

        $contents = stripComments(strtolower((string) file_get_contents($file->getPathname())));

        foreach ($vendors as $vendor) {
            if (str_contains($contents, $vendor)) {
                $offenders[] = "{$path} mentions {$vendor}";
            }
        }
    }

    return $offenders;
}

it('names no video vendor in business logic', function (): void {
    $offenders = [];

    foreach (glob(base_path('app/Modules/*'), GLOB_ONLYDIR) ?: [] as $module) {
        $offenders = array_merge(
            $offenders,
            scanForVendorNames($module.'/Actions', '/\.php$/'),
            scanForVendorNames($module.'/Http', '/\.php$/'),
            scanForVendorNames($module.'/Models', '/\.php$/'),
        );
    }

    expect($offenders)->toBe([]);
});

it('names no video vendor in the frontend', function (): void {
    $frontend = base_path('../frontend/src');

    expect(scanForVendorNames($frontend, '/\.(ts|tsx)$/'))->toBe([]);
});
