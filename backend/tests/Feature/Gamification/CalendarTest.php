<?php

declare(strict_types=1);

use App\Modules\Gamification\Support\GamificationCalendar;
use Carbon\CarbonImmutable;

/**
 * The day and week boundary (FR-019 · Q5 · SC-010).
 *
 * Small, and load-bearing out of all proportion to its size: the daily cap, the
 * streak and the leaderboard period all read this one class, so a boundary that
 * is three hours out moves all three at once — and moves them silently, because
 * every one of them still returns a plausible number.
 */
function calendar(): GamificationCalendar
{
    return app(GamificationCalendar::class);
}

it('puts 11pm Doha in the Doha day, not the next UTC one', function (): void {
    // 2026-08-19 23:00 Doha is 2026-08-19 20:00 UTC — same date either way, so
    // this pair alone would prove nothing. The one that bites is just after
    // midnight local, which is still YESTERDAY in UTC.
    $lateEvening = CarbonImmutable::parse('2026-08-19 23:00', 'Asia/Qatar');
    $justAfterMidnight = CarbonImmutable::parse('2026-08-20 00:30', 'Asia/Qatar');

    expect(calendar()->dayKey($lateEvening))->toBe('2026-08-19')
        ->and(calendar()->dayKey($justAfterMidnight))->toBe('2026-08-20')
        // …and that same moment is still the 19th in UTC. A cap computed on the
        // UTC day would still be enforcing yesterday's allowance.
        ->and($justAfterMidnight->utc()->format('Y-m-d'))->toBe('2026-08-19');
});

it('bounds a day as a half-open UTC window that starts at Doha midnight', function (): void {
    [$start, $end] = calendar()->dayBounds('2026-08-20');

    // Doha is UTC+3 all year, so the local day opens at 21:00 UTC the day before.
    expect($start->format('Y-m-d H:i'))->toBe('2026-08-19 21:00')
        ->and($end->format('Y-m-d H:i'))->toBe('2026-08-20 21:00')
        // Half-open: `<= end of day` would bind midnight and drop the last second
        // of writes, the boundary bug FreezePeriod::covering() already paid for.
        ->and($end->diffInHours($start))->toBe(-24.0);
});

it('starts the week on Sunday, so a weekend lands in one key', function (): void {
    // 2026-08-16 is a Sunday; 2026-08-22 the Saturday that closes the same week.
    $sunday = CarbonImmutable::parse('2026-08-16 09:00', 'Asia/Qatar');
    $saturday = CarbonImmutable::parse('2026-08-22 21:00', 'Asia/Qatar');
    $nextSunday = CarbonImmutable::parse('2026-08-23 00:30', 'Asia/Qatar');

    expect($sunday->dayOfWeek)->toBe(CarbonImmutable::SUNDAY)
        ->and(calendar()->weekKey($sunday))->toBe(calendar()->weekKey($saturday))
        ->and(calendar()->weekKey($nextSunday))->not->toBe(calendar()->weekKey($saturday));
});

/*
 * ⚠️ THE ONE THAT CATCHES `format('W')`.
 *
 * ISO weeks begin on Monday, so under it a Sunday carries a different number from
 * the six days that follow it and one week splits into two period keys — a
 * leaderboard that resets on Sunday evening for no reason anyone can see. The
 * assertion above compares Sunday to Saturday, which passes under ISO too; this
 * one compares Sunday to the MONDAY after it, which does not.
 */
it('keeps Sunday and the Monday after it in the same week', function (): void {
    $sunday = CarbonImmutable::parse('2026-08-16 12:00', 'Asia/Qatar');
    $monday = CarbonImmutable::parse('2026-08-17 12:00', 'Asia/Qatar');

    expect(calendar()->weekKey($sunday))->toBe(calendar()->weekKey($monday));
});

it('leaves the application timezone alone', function (): void {
    // Stored timestamps are UTC and stay UTC. What this phase converts is the
    // BOUNDARY, in GamificationCalendar and nowhere else.
    expect(config('app.timezone'))->toBe('UTC')
        ->and(calendar()->timezone())->toBe('Asia/Qatar');
});
