<?php

declare(strict_types=1);

/*
| FR-002 as a gate rather than a convention.
|
| Same shape as Media's and Notifications' ProviderAgnosticTest: business logic
| may name a CAPABILITY, never a vendor. The whole promise of 017 — that changing
| provider is one adapter and one config value — is worth exactly as much as this
| test and no more, because a rule with no gate is a rule that survives until the
| first person in a hurry.
|
| The sanctioned exceptions are listed below and nowhere else. Note what is NOT
| among them: `Actions/`, `Models/`, `Http/`, `Jobs/`, `Policies/`. An import of
| `Agence104\LiveKit\*` in any of those makes the library the interface, and the
| adapter a decoration.
*/

/** @return list<string> */
function livekitOffenders(): array
{
    $root = base_path('app');
    $needles = ['livekit', 'agence104'];

    // The adapter is the one file that may know the name — that is its entire
    // job. The service provider carries the `match` arm FR-001 requires: one
    // line, at the inversion point, where a reader looking for "which provider"
    // will actually look.
    $allowed = [
        '/Modules/LiveSessions/Providers/LiveKitBroadcastProvider.php',
        '/Modules/LiveSessions/LiveSessionsServiceProvider.php',
    ];

    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        foreach ($allowed as $exception) {
            if (str_ends_with($path, $exception)) {
                continue 2;
            }
        }

        $contents = strtolower((string) file_get_contents($file->getPathname()));

        foreach ($needles as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = "{$path} mentions {$needle}";
            }
        }
    }

    return $offenders;
}

it('names the broadcast provider in one file and its binding, nowhere else', function (): void {
    expect(livekitOffenders())->toBe([]);
});

// The exception has to BE an exception: if the adapter stopped naming the
// library, this test would be passing over an empty rule.
it('finds the name where it belongs', function (): void {
    $adapter = base_path('app/Modules/LiveSessions/Providers/LiveKitBroadcastProvider.php');

    expect(strtolower((string) file_get_contents($adapter)))->toContain('agence104');
});
