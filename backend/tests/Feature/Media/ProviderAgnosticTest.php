<?php

declare(strict_types=1);

/*
| The rule that keeps VideoProviderInterface a contract rather than an intention.
|
| Same shape as ProviderAgnosticTest for notification channels: business logic
| may name a capability, never a vendor. Providers/ is the one place a vendor name
| belongs, by definition.
*/

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

        $contents = strtolower((string) file_get_contents($file->getPathname()));

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
