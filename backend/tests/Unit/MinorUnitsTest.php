<?php

declare(strict_types=1);

use App\Filament\Support\MoneyInput;
use App\Filament\Support\PercentInput;
use App\Shared\Support\MinorUnits;

/*
| المبلغُ يُكتَبُ بالوحدةِ الكبرى ويُخزَّنُ بالصغرى (قرارُ المالك ٢٠٢٦-٠٩-٢٧)،
| والتحويلُ نصّيٌّ لا ضربٌ في ١٠٠: ‏`(int) (19.99 * 100)` تساوي 1998.
|
| ⚠️ لا قاعدةَ بياناتٍ هنا: دوالُّ خالصة.
*/
it('converts a typed major amount to minor units without float drift', function (mixed $major, ?int $minor): void {
    expect(MinorUnits::fromMajor($major))->toBe($minor);
})->with([
    'two decimals' => ['49.99', 4999],
    'the float trap' => ['19.99', 1999],
    'one decimal' => ['5.5', 550],
    'whole' => ['300', 30_000],
    'leading dot' => ['.75', 75],
    'zero' => ['0', 0],
    'an int' => [12, 1200],
    'a float from the numeric cast' => [19.99, 1999],
    'negative' => ['-1.25', -125],
    'empty' => ['', null],
    'null' => [null, null],
    'not a number' => ['abc', null],
    'three decimals' => ['1.999', null],
]);

it('shows a stored minor amount in major units', function (?int $minor, ?string $major): void {
    expect(MinorUnits::toMajor($minor))->toBe($major);
})->with([
    [4999, '49.99'],
    [5, '0.05'],
    [30_000, '300.00'],
    [0, '0.00'],
    [-125, '-1.25'],
    [null, null],
]);

it('opens the money box on the stored amount whatever numeric shape the cast gave it', function (): void {
    // `numeric()` hands the hydration hook 30000.0, not 30000 — measured, and
    // the reason the box once opened empty.
    expect(MoneyInput::hydrate(30000.0))->toBe('300.00')
        ->and(MoneyInput::hydrate(4999))->toBe('49.99')
        ->and(MoneyInput::hydrate('4999'))->toBe('49.99')
        ->and(MoneyInput::hydrate(null))->toBeNull()
        ->and(MoneyInput::currencyLabel('QAR'))->toBe('ر.ق')
        ->and(MoneyInput::currencyLabel('XYZ'))->toBe('XYZ');
});

it('shows a stored ratio as a percentage and stores it back as the ratio', function (): void {
    expect(PercentInput::toPercent(0.5))->toBe(50.0)
        ->and(PercentInput::toPercent(0.57))->toBe(57.0)
        ->and(PercentInput::toPercent(0.333))->toBe(33.3)
        ->and(PercentInput::toRatio(57))->toBe(0.57)
        ->and(PercentInput::toRatio(5))->toBe(0.05);
});
