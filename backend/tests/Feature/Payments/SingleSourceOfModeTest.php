<?php

declare(strict_types=1);

use App\Modules\Payments\Enums\BillingCadence;
use App\Modules\Payments\Enums\BillingMode;
use Symfony\Component\Finder\Finder;

/*
| FR-013 — every decision point reads the mode from ONE place.
|
| Modelled on the existing ProviderAgnosticTest, and for the same reason: the
| rule is textual, so the guard is textual. A second `=== 'prepaid_credits'`
| somewhere is a second decision, and the two agree right up until one of them is
| updated.
|
| The scan reads CODE, not prose — a docblock naming a mode to explain the rule
| must not be what fails the build.
*/

/** @return list<string> */
function modeLiterals(): array
{
    return array_merge(
        array_map(fn (BillingMode $mode): string => $mode->value, BillingMode::cases()),
        array_map(fn (BillingCadence $cadence): string => $cadence->value, BillingCadence::cases()),
    );
}

/**
 * The files allowed to name a mode, and why each one is.
 *
 * Kept SHORT on purpose: a growing exemption list is how a single-source rule
 * stops being one.
 *
 * @return list<string>
 */
function modeSourceFiles(): array
{
    return [
        // The enums themselves — they define the values.
        'Modules/Payments/Enums/BillingMode.php',
        'Modules/Payments/Enums/BillingCadence.php',
        // The single reader (FR-013) and the single writer.
        'Modules/Payments/Support/BillingSettings.php',
    ];
}

/** One file's source with comments removed, so prose cannot fail the build. */
function sourceWithoutComments(string $file): string
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

it('names a billing mode nowhere outside BillingSettings and the enums', function (): void {
    $literals = modeLiterals();
    $allowed = modeSourceFiles();

    // A scan that found no files would pass by finding nothing.
    $files = iterator_to_array(Finder::create()->files()->in(app_path())->name('*.php'));

    expect($files)->not->toBeEmpty();

    $offenders = [];

    foreach ($files as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($relative, $allowed, true)) {
            continue;
        }

        $contents = sourceWithoutComments($file->getPathname());

        foreach ($literals as $literal) {
            if (str_contains($contents, "'{$literal}'")) {
                $offenders[] = $relative.' → '.$literal;
            }
        }
    }

    expect($offenders)->toBe([]);
});

// The exemption list must name files that exist. A stale entry silently exempts
// nothing while reading as though it exempts something.
it('exempts only files that exist', function (): void {
    foreach (modeSourceFiles() as $relative) {
        expect(file_exists(app_path($relative)))->toBeTrue("{$relative} is exempted but missing");
    }
});

// And the scan must be able to find a violation at all — a stripper that
// returned an empty string would make the case above pass over everything.
it('would catch a literal outside the allowed files', function (): void {
    $source = sourceWithoutComments(app_path('Modules/Payments/Enums/BillingMode.php'));

    expect($source)->toContain("'prepaid_credits'");
});
