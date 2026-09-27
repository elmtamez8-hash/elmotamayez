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

it('names the currency beside the money box', function (): void {
    expect(MoneyInput::currencyLabel('QAR'))->toBe('ر.ق')
        ->and(MoneyInput::currencyLabel('XYZ'))->toBe('XYZ')
        ->and(MoneyInput::currencyLabel(null))->toBe('');
});

/*
| ⚠️ Filament's `numeric()` hands the model's setter a FLOAT, and
| `number_format()` rounds: 49.999 would have become 5000 in silence.
*/
it('refuses a float with a third decimal instead of rounding it', function (): void {
    expect(MinorUnits::fromMajor(49.999))->toBeNull()
        ->and(MinorUnits::fromMajor(0.5))->toBe(50)
        ->and(MinorUnits::fromMajor(1_000_000.0))->toBe(100_000_000)
        ->and(MinorUnits::fromMajor(0.0))->toBe(0);
});

it('throws on a filled value that is not an amount, and lets blank through as null', function (): void {
    expect(MinorUnits::fromMajorOrFail(''))->toBeNull()
        ->and(MinorUnits::fromMajorOrFail('  '))->toBeNull()
        ->and(MinorUnits::fromMajorOrFail(null))->toBeNull()
        ->and(MinorUnits::fromMajorOrFail('49.99'))->toBe(4999)
        ->and(fn () => MinorUnits::fromMajorOrFail('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MinorUnits::fromMajorOrFail('1.999'))->toThrow(InvalidArgumentException::class);
});

it('shows a stored ratio as a percentage and stores it back as the ratio', function (): void {
    expect(PercentInput::toPercent(0.5))->toBe(50.0)
        ->and(PercentInput::toPercent(0.57))->toBe(57.0)
        ->and(PercentInput::toPercent(0.333))->toBe(33.3)
        ->and(PercentInput::toRatio(57))->toBe(0.57)
        ->and(PercentInput::toRatio(5))->toBe(0.05);
});

it('formats a minor amount for reading with the thousands separator', function (): void {
    // `toMajor()` is the form's spelling («1000.00»); `display()` is the reader's.
    expect(MinorUnits::display(100_000, 'ر.ق'))->toBe('1,000.00 ر.ق')
        ->and(MinorUnits::display(123_456_789))->toBe('1,234,567.89')
        ->and(MinorUnits::display(5, 'QAR'))->toBe('0.05 QAR')
        ->and(MinorUnits::display(-150_000))->toBe('-1,500.00')
        ->and(MinorUnits::display(null, 'ر.ق'))->toBeNull()
        ->and(MinorUnits::toMajor(100_000))->toBe('1000.00');
});
