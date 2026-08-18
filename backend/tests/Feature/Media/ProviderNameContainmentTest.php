<?php

declare(strict_types=1);

/*
| SC-005 · FR-019 — the provider's name lives in ONE adapter and its binding.
|
| Same shape as 017's guard for the broadcast provider, and for the same reason: the
| promise that changing provider is one file and one config value is worth exactly
| as much as this test and no more. A rule with no gate survives until the first
| person in a hurry.
|
| ⚠️ NOTE WHAT IS NOT AMONG THE EXCEPTIONS: `Actions/`, `Models/`, `Http/`, `Jobs/`,
| `Support/`. In particular the RESOLVER is not allowed to name a provider — it is
| handed the map, so that the list of providers exists in one place and cannot drift
| into being resolvable in one direction only.
|
| `config/` is deliberately outside this scan: an account's keys and its library id
| are configuration by definition, and a configuration file that cannot name what it
| configures is a configuration file that cannot be read.
*/

/** @return list<string> */
function mediaProviderOffenders(): array
{
    $root = base_path('app');

    // 'b-cdn' as well as the vendor's name: the delivery hostname identifies the
    // provider just as plainly, and a URL built somewhere else would leak it while
    // passing a scan that only looked for the word.
    $needles = ['bunny', 'b-cdn', 'bunnycdn'];

    $allowed = [
        '/Modules/Media/Providers/BunnyMediaProvider.php',
        '/Modules/Media/MediaServiceProvider.php',
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

it('names the media provider in one file and its binding, nowhere else', function (): void {
    expect(mediaProviderOffenders())->toBe([]);
});

/*
| ⚠️ THE EXCEPTION HAS TO BE AN EXCEPTION.
|
| Without this, a day when the adapter stopped naming the provider — renamed,
| gutted, replaced by a stub — would leave the rule above passing over an empty set
| and reporting containment it was no longer measuring. This is the lesson 017 paid
| for and it is cheaper to repeat than to relearn.
*/
it('finds the name where it belongs', function (): void {
    $adapter = base_path('app/Modules/Media/Providers/BunnyMediaProvider.php');

    expect(strtolower((string) file_get_contents($adapter)))->toContain('bunnycdn');
});

it('finds the name at the inversion point', function (): void {
    $binding = base_path('app/Modules/Media/MediaServiceProvider.php');

    expect(strtolower((string) file_get_contents($binding)))->toContain('bunny');
});

/*
| SC-007 — THE SCOPE OF THIS PHASE, MEASURED RATHER THAN PROMISED.
|
| 017 promised not to touch `Modules/Media/`; this phase promises the mirror image
| about `Modules/LiveSessions/` — with ONE declared exception, the recording ingest
| path, which is this phase's own subject. Attendance, seats, the schedule and the
| register are what the promise is actually about (research §R11), and they are what
| is checked here.
|
| ⚠️ AND IT IS CHECKED BY DIRECTORY, NOT BY DIFF. A git diff measures one commit; a
| structural rule holds for every later one. Any Action, Model, Policy or controller
| under LiveSessions that starts asking about media is what this catches.
*/
it('leaves the parts of the live-sessions module this phase promised not to touch', function (): void {
    /*
     * ⚠️ `Jobs/` IS IN THE LIST NOW, AND THE EXCEPTION IS ONE FILE.
     *
     * The declared exception is "the recording ingest path". The check granted it
     * by leaving the WHOLE `Jobs/` directory out of the sealed set — so any job
     * added later could reach for the media provider and this rule would never
     * notice. An exception the size of a folder is not an exception.
     */
    $sealed = ['Actions', 'Models', 'Policies', 'Http', 'Listeners', 'Support', 'Jobs'];
    $exempt = ['IngestSessionRecordingJob.php'];
    $offenders = [];

    foreach ($sealed as $directory) {
        $root = base_path("app/Modules/LiveSessions/{$directory}");

        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            if (in_array($file->getFilename(), $exempt, true)) {
                continue;
            }

            $contents = strtolower((string) file_get_contents($file->getPathname()));

            // The provider name, the resolver, and the added capability. Any of the
            // three appearing here means the delivery decision has spread out of the
            // one job that owns it.
            foreach (['bunny', 'mediaproviderresolver', 'ingestfromurl'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = str_replace('\\', '/', $file->getPathname())." mentions {$needle}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

// And the two contexts 006 and 014 fenced off stay fenced: changing who serves the
// bytes has nothing to say about a payment or a teacher's pay.
it('touches neither billing nor settlement', function (): void {
    $offenders = [];

    foreach (['Payments', 'Settlement'] as $module) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path("app/Modules/{$module}")),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $contents = strtolower((string) file_get_contents($file->getPathname()));

            foreach (['bunny', 'ingestfromurl', 'mediaproviderresolver'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = str_replace('\\', '/', $file->getPathname())." mentions {$needle}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

/*
| ⚠️ AND THE EXEMPTION ITSELF IS MEASURED, for the same reason the adapter's own
| name is: an exemption for a file that has stopped needing it is an exemption
| nobody notices has become a hole. If the ingest job ever stops naming the
| capability, the exemption must go with it.
*/
it('finds the declared exception still using what it was granted', function (): void {
    $job = base_path('app/Modules/LiveSessions/Jobs/IngestSessionRecordingJob.php');

    expect(strtolower((string) file_get_contents($job)))->toContain('ingestfromurl');
});
