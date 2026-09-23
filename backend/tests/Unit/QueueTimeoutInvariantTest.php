<?php

declare(strict_types=1);

/*
| Redis hands a reserved job back to its queue once the popping connection's
| `retry_after` has passed, whether or not the first worker is still on it. With
| `tries: 1` the second pop then writes a MaxAttemptsExceeded row to `failed_jobs`
| and fires `failed()` while the original is still running. Laravel's rule is the
| one asserted here: a worker's timeout must stay below its connection's
| `retry_after`.
|
| Read from config and source only — no database, no queue — so the defect is
| caught on the line that introduces it: a supervisor given a longer timeout, a
| job given a longer `$timeout`, or a connection whose `retry_after` is lowered.
*/

/**
 * Every supervisor as it actually runs in production: `defaults` merged with the
 * `environments.production` overrides (the connection lives only in defaults).
 *
 * @return array<string, array<string, mixed>>
 */
function queueInvariantSupervisors(): array
{
    /** @var array<string, array<string, mixed>> $defaults */
    $defaults = config('horizon.defaults');
    /** @var array<string, array<string, mixed>> $production */
    $production = config('horizon.environments.production', []);

    $merged = [];
    foreach ($defaults as $name => $options) {
        $merged[$name] = array_merge($options, $production[$name] ?? []);
    }

    return $merged;
}

it('gives every supervisor a connection whose retry_after outlives its timeout', function (): void {
    $violations = [];

    foreach (queueInvariantSupervisors() as $name => $options) {
        $connection = (string) $options['connection'];
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        expect($retryAfter)->not->toBeNull("{$name} pops with [{$connection}], which is not a queue connection");

        if ((int) $options['timeout'] >= (int) $retryAfter) {
            $violations[] = "{$name}: timeout {$options['timeout']} >= {$connection}.retry_after {$retryAfter}";
        }
    }

    expect($violations)->toBe([]);
});

it('watches every connection:queue pair a supervisor works', function (): void {
    $missing = [];

    foreach (queueInvariantSupervisors() as $name => $options) {
        foreach ((array) $options['queue'] as $queue) {
            $pair = $options['connection'].':'.$queue;

            if (! array_key_exists($pair, (array) config('horizon.waits'))) {
                $missing[] = "{$name} → {$pair}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('keeps every job-level $timeout below the retry_after of the worker that pops it', function (): void {
    $queueToConnection = [];
    foreach (queueInvariantSupervisors() as $options) {
        foreach ((array) $options['queue'] as $queue) {
            $queueToConnection[$queue] = (string) $options['connection'];
        }
    }

    $checked = 0;
    $violations = [];

    foreach (glob(app_path('Modules/*/Jobs/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match('/public int \$timeout = (\d+);/', $source, $timeout) !== 1) {
            continue;
        }

        $checked++;

        // A job with its own timeout must name its queue, or nothing here can
        // say which worker — and so which retry_after — it runs under.
        if (preg_match("/onQueue\\('([a-z-]+)'\\)/", $source, $queue) !== 1) {
            $violations[] = basename($file).': declares a $timeout but names no queue';

            continue;
        }

        $connection = $queueToConnection[$queue[1]] ?? null;

        if ($connection === null) {
            $violations[] = basename($file).": queue [{$queue[1]}] has no supervisor";

            continue;
        }

        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        if ((int) $timeout[1] >= $retryAfter) {
            $violations[] = basename($file).": \$timeout {$timeout[1]} >= {$connection}.retry_after {$retryAfter}";
        }
    }

    // Guard against a vacuous pass: five long jobs carry a timeout today.
    expect($checked)->toBeGreaterThanOrEqual(5)
        ->and($violations)->toBe([]);
});
