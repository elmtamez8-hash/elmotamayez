<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;

/*
| ⛔ Owner decision (2026-09-27): the admin types and reads MAJOR units, the
| `*_minor` column keeps MINOR units, and the MODEL converts — never the form.
|
| No database here: the attribute is a pure function of the row's attributes,
| and `RefreshDatabase` on a unit test pays for a migration it does not need.
*/
it('reads and writes a plan price in major units over the minor column', function (mixed $typed, int $stored, string $read): void {
    $plan = new Plan;
    $plan->price = $typed;

    expect($plan->price_minor)->toBe($stored)
        ->and($plan->getAttributes()['price_minor'])->toBe($stored)
        ->and($plan->price)->toBe($read);
})->with([
    'the typed string' => ['49.99', 4999, '49.99'],
    'the float numeric() hands over' => [49.99, 4999, '49.99'],
    'the float trap' => [19.99, 1999, '19.99'],
    'zero' => [0, 0, '0.00'],
    'a half' => [0.5, 50, '0.50'],
    'a million' => ['1000000', 100_000_000, '1000000.00'],
    'a million as a float' => [1_000_000.0, 100_000_000, '1000000.00'],
]);

it('maps null and blank to null, both ways', function (mixed $blank): void {
    $plan = new Plan;
    $plan->price = $blank;

    expect($plan->price_minor)->toBeNull()
        ->and($plan->price)->toBeNull();
})->with(['null' => [null], 'empty' => [''], 'spaces' => ['  ']]);

it('refuses a value that is not an amount instead of storing null', function (mixed $bad): void {
    $plan = new Plan;
    $plan->forceFill(['price_minor' => 30_000]);

    expect(fn () => $plan->price = $bad)->toThrow(InvalidArgumentException::class)
        ->and($plan->price_minor)->toBe(30_000);
})->with(['words' => ['abc'], 'a third decimal' => ['49.999'], 'a float third decimal' => [49.999]]);

it('keeps the virtual attribute out of every serialisation', function (): void {
    $plan = new Plan;
    $plan->forceFill(['price_minor' => 4999]);

    // `$appends` would change every API payload that serialises the model.
    expect($plan->toArray())->not->toHaveKey('price')
        ->and($plan->toArray()['price_minor'])->toBe(4999);
});

it('keeps the plan price out of mass assignment, like its column', function (): void {
    // `SavePlan` fills from a teacher's form: a fillable `price` would be the
    // platform's number one extra key away from a teacher.
    expect((new Plan)->isFillable('price'))->toBeFalse()
        ->and((new Plan)->isFillable('price_minor'))->toBeFalse()
        ->and((new Course)->isFillable('price'))->toBeFalse();
});

it('gives every money model the same major-unit reading', function (): void {
    $course = (new Course)->forceFill(['price_minor' => 12_345]);
    $order = (new Order)->forceFill(['amount_minor' => 48_000]);
    $subscription = (new Subscription)->forceFill(['price_minor' => 5]);
    $transaction = (new PaymentTransaction)->forceFill(['amount_minor' => 48_000, 'refunded_minor' => 1_600]);

    expect($course->price)->toBe('123.45')
        ->and($order->amount)->toBe('480.00')
        ->and($subscription->price)->toBe('0.05')
        ->and($transaction->amount)->toBe('480.00')
        ->and($transaction->refunded_amount)->toBe('16.00');
});

it('has no setter for the refund, which one Action writes', function (): void {
    $transaction = new PaymentTransaction;
    $transaction->refunded_amount = '16.00';

    // No setter: the value lands on an attribute of that name, never on the column.
    expect($transaction->getAttributes())->not->toHaveKey('refunded_minor');
});

describe('coupon', function (): void {
    it('reads a fixed coupon\'s value as a major amount and writes it back in minor units', function (): void {
        $coupon = new Coupon(['value_kind' => 'fixed_minor', 'amount' => '49.99']);

        expect($coupon->value)->toBe(4999)
            ->and($coupon->amount)->toBe('49.99');

        $coupon->amount = 12.5;

        expect($coupon->value)->toBe(1250);
    });

    it('never reads a percent as an amount', function (): void {
        $coupon = new Coupon(['value_kind' => 'percent', 'value' => 50]);

        expect($coupon->value)->toBe(50)
            ->and($coupon->amount)->toBeNull();
    });
});
