<?php

declare(strict_types=1);

use App\Modules\Notifications\Support\PhoneNumber;

/*
| One number written six ways is one number (spec 020, FR-016).
|
| A unit test rather than a feature one: nothing here touches a database, and
| the whole value of normalising at write time is that this is the only place
| that ever has to know how a number is shaped.
*/

it('reads every way a Qatari number gets typed as one number', function (string $raw): void {
    expect(PhoneNumber::toE164($raw, '974'))->toBe('+97433123456');
})->with([
    'already international' => '+97433123456',
    'international with spaces' => '+974 3312 3456',
    'international with dashes' => '+974-3312-3456',
    'dialled with 00' => '0097433123456',
    'country code without plus' => '97433123456',
    'local with trunk zero' => '033123456',
    'local, bare' => '33123456',
]);

it('refuses what it cannot read rather than guessing', function (mixed $raw): void {
    // Refused, never guessed: a guessed number is a message to a stranger, and
    // the person who typed it hears nothing and assumes it worked.
    expect(PhoneNumber::toE164($raw, '974'))->toBeNull();
})->with([
    'empty' => '',
    'letters only' => 'رقمي',
    'too short' => '3312',
    'far too long' => '+9743312345678901234',
    'zeroes only' => '0000',
]);

it('masks all but the last four digits for a log line', function (): void {
    expect(PhoneNumber::mask('+97433123456'))->toBe('********3456')
        ->and(PhoneNumber::mask('+974'))->toBe('****');
});
