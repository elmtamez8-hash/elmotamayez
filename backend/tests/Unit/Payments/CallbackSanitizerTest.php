<?php

declare(strict_types=1);

use App\Modules\Payments\Support\CallbackPayloadSanitizer;

/*
| The second scrub — the one that runs on a body no provider's parser ever saw.
|
| ⚠️ NOTHING HERE COVERS `hash_equals`. No assertion can measure constant time,
| and a test named after it would claim a guarantee it does not provide. That
| rule is held by review, and it is written where the comparison is.
*/

it('redacts a card number whatever key it arrives under', function (): void {
    // 4111 1111 1111 1111 — a valid Luhn, under an innocent key.
    $clean = CallbackPayloadSanitizer::scrub(['note' => '4111111111111111']);

    expect($clean['note'])->toBe('[redacted]');
});

it('keeps digit runs that are not card numbers', function (): void {
    // ⚠️ THE REASON THE CHECK IS LUHN AND NOT "13 OR MORE DIGITS". An order id, a
    // phone number and a timestamp are all long digit runs, and redacting them
    // would empty the record this class exists to preserve.
    $clean = CallbackPayloadSanitizer::scrub([
        'order_reference' => '1234567890123456',
        'phone' => '+97455512345',
        'timestamp' => '20260811120000',
    ]);

    expect($clean['order_reference'])->toBe('1234567890123456')
        ->and($clean['phone'])->toBe('+97455512345')
        ->and($clean['timestamp'])->toBe('20260811120000');
});

it('drops anything whose key names a secret, whatever the value looks like', function (): void {
    $clean = CallbackPayloadSanitizer::scrub([
        'signature' => 'abc123',
        'api_key' => 'k_live_1',
        'card_holder' => 'أحمد',
        'reference' => 'ORD-9',
    ]);

    expect($clean['signature'])->toBe('[redacted]')
        ->and($clean['api_key'])->toBe('[redacted]')
        ->and($clean['card_holder'])->toBe('[redacted]')
        // Everything else survives — a sanitizer that emptied the row would
        // satisfy SC-002's "reject" and defeat its "record".
        ->and($clean['reference'])->toBe('ORD-9');
});

it('bounds what an attacker can make us store', function (): void {
    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too far']]]]]];

    expect(CallbackPayloadSanitizer::scrub($deep)['a']['b']['c']['d']['e'])
        ->toBe(['_truncated' => 'max depth']);

    $long = CallbackPayloadSanitizer::scrub(['blob' => str_repeat('x', 900)]);

    expect(mb_strlen($long['blob']))->toBe(501);

    $wide = CallbackPayloadSanitizer::scrub(array_fill_keys(
        array_map(static fn (int $i): string => "k{$i}", range(1, 150)),
        'v',
    ));

    expect($wide)->toHaveKey('_truncated');
});

it('scrubs nested structures, not just the top level', function (): void {
    $clean = CallbackPayloadSanitizer::scrub([
        'customer' => ['card' => '4111111111111111', 'name' => 'سارة'],
    ]);

    expect($clean['customer']['card'])->toBe('[redacted]')
        ->and($clean['customer']['name'])->toBe('سارة');
});
