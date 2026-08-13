<?php

declare(strict_types=1);

use App\Modules\Payments\Data\ReconciliationWindow;
use App\Modules\Payments\Models\PaymentReconciliationRun;
use Carbon\CarbonImmutable;

/*
| `[from, to)` — inclusive start, exclusive end.
|
| ⚠️ A UNIT TEST BECAUSE THE BUG IT GUARDS IS ONE SECOND WIDE. Closed at both
| ends, a payment settled on the boundary second is reconciled by two consecutive
| runs; open at both, by neither. The first is survivable only because the effect
| is idempotent; the second is a student who paid and stayed blocked, and no
| feature test would ever stage the exact second that produces it.
*/

it('includes its start and excludes its end', function (): void {
    $window = new ReconciliationWindow(
        CarbonImmutable::parse('2026-08-13 10:00:00'),
        CarbonImmutable::parse('2026-08-13 11:00:00'),
    );

    expect($window->contains(CarbonImmutable::parse('2026-08-13 10:00:00')))->toBeTrue()
        ->and($window->contains(CarbonImmutable::parse('2026-08-13 10:30:00')))->toBeTrue()
        // The boundary second belongs to the NEXT window, and to it alone.
        ->and($window->contains(CarbonImmutable::parse('2026-08-13 11:00:00')))->toBeFalse()
        ->and($window->contains(CarbonImmutable::parse('2026-08-13 09:59:59')))->toBeFalse();
});

it('starts the next window exactly where the last one stopped', function (): void {
    $previous = new PaymentReconciliationRun([
        'window_from' => CarbonImmutable::parse('2026-08-13 09:00:00'),
        'window_to' => CarbonImmutable::parse('2026-08-13 10:00:00'),
    ]);

    $next = ReconciliationWindow::following($previous, CarbonImmutable::parse('2026-08-13 12:30:00'));

    // ⚠️ NOT `now()->subHour()`, WHICH LOOKS EQUIVALENT AND IS NOT. This run is
    // two and a half hours late; a clock-derived start would silently skip the
    // ninety minutes nobody looked at, and a platform whose worker was down
    // overnight would reconcile the last hour and call the night resolved.
    expect($next->from->toDateTimeString())->toBe('2026-08-13 10:00:00')
        ->and($next->to->toDateTimeString())->toBe('2026-08-13 12:30:00');
});

it('reaches back a fixed lookback on the first run of a deployment', function (): void {
    $first = ReconciliationWindow::following(null, CarbonImmutable::parse('2026-08-13 12:00:00'), 60);

    expect($first->from->toDateTimeString())->toBe('2026-08-13 11:00:00')
        ->and($first->isEmpty())->toBeFalse();
});

it('refuses to invert when the clock moves backwards', function (): void {
    $previous = new PaymentReconciliationRun([
        'window_from' => CarbonImmutable::parse('2026-08-13 11:00:00'),
        'window_to' => CarbonImmutable::parse('2026-08-13 12:00:00'),
    ]);

    // An NTP correction, or a run recorded ahead of this one. An empty window is
    // the honest answer; `from` after `to` would be handed to a provider as a
    // range meaning nothing, and providers differ on what they do with one.
    $next = ReconciliationWindow::following($previous, CarbonImmutable::parse('2026-08-13 11:30:00'));

    expect($next->from->toDateTimeString())->toBe('2026-08-13 11:30:00')
        ->and($next->isEmpty())->toBeTrue();
});
