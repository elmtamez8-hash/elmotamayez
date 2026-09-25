<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
| The platform's timezone has ONE source, and the scheduler reads it.
|
| `routes/console.php` used to spell `Asia/Qatar` on eight lines beside two config
| files each declaring it again — four places for one fact, which agree until the
| first one moves and then put the week's seal, the birthday sweep and the report
| cards on a different calendar from the lessons they describe.
|
| ⚠️ COMMENTS ARE STRIPPED FIRST. The file explains in prose why these schedules
| carry a zone at all; a guard that failed on that explanation would teach people
| to delete it.
*/

function consoleRoutesCode(): string
{
    $source = (string) file_get_contents(base_path('routes/console.php'));
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

it('names no timezone as a literal in the schedule', function (): void {
    $code = consoleRoutesCode();

    // Positive control: the scan is looking at the schedule, not at nothing.
    expect($code)->toContain("config('sessions.timezone')")
        ->and($code)->toContain('->timezone($platformTimezone)');

    expect($code)->not->toMatch('~[\'"](Asia|Africa|Europe|America|UTC)(/[A-Za-z_]+)?[\'"]~');
});

it('still runs the calendar schedules on the platform zone, which is Asia/Qatar today', function (): void {
    expect(config('sessions.timezone'))->toBe('Asia/Qatar')
        ->and(config('notifications.default_timezone'))->toBe(config('sessions.timezone'));

    $zoned = collect(app(Schedule::class)->events())
        ->map(static fn ($event): string => (string) $event->timezone)
        // Every other event carries the scheduler's own zone, `app.timezone`.
        ->reject(static fn (string $zone): bool => $zone === '' || $zone === config('app.timezone'))
        ->values();

    // The eight schedules that answer a question about a DATE. A ninth appearing,
    // or one disappearing, is a change in behaviour worth reading.
    expect($zoned)->toHaveCount(8)
        ->and($zoned->unique()->all())->toBe(['Asia/Qatar']);
});
