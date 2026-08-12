<?php

declare(strict_types=1);

use App\Modules\Payments\Providers\ManualTransferProvider;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\FakePaymentProvider;

/*
| Resolution, and the shape of a refusal.
|
| A unit test because the registry is a lookup table: it deserves to fail for
| lookup reasons, not because a workspace fixture drifted.
*/

it('resolves a provider by its identifier', function () {
    $manual = new ManualTransferProvider;

    $registry = new PaymentProviderRegistry([$manual]);

    expect($registry->get('manual'))->toBe($manual)
        ->and($registry->has('manual'))->toBeTrue()
        ->and($registry->identifiers())->toBe(['manual']);
});

it('answers 404 for an identifier nobody registered', function () {
    $registry = new PaymentProviderRegistry([new ManualTransferProvider]);

    expect($registry->has('paymob'))->toBeFalse();

    // ⚠️ 404, never 403. A webhook URL carries the provider id, so an
    // unregistered one is a statement about a route that does not exist — 403
    // would confirm to a prober that the id names something real.
    expect(fn () => $registry->get('paymob'))
        ->toThrow(NotFoundHttpException::class);
});

it('reads its default from config, not from registration order', function () {
    $fake = new FakePaymentProvider;
    $fake->identifier = 'gateway-under-test';

    // Registered FIRST, so an implementation that returned "the first tagged
    // provider" would pass while meaning something else entirely.
    $registry = new PaymentProviderRegistry([$fake, new ManualTransferProvider]);

    config()->set('payments.default', 'manual');

    expect($registry->default())->toBeInstanceOf(ManualTransferProvider::class);
});
