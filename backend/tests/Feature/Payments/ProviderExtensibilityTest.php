<?php

declare(strict_types=1);

use App\Modules\Payments\Providers\ManualTransferProvider;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use Symfony\Component\Finder\Finder;
use Tests\Support\FakePaymentProvider;

/*
| NFR-003 — "an explicit architectural acceptance test", in its own words.
|
| ⚠️ AND IT IS NOT THE REGISTRY UNIT TEST. That one proves a lookup table finds
| what was put in it. This proves the CLAIM: a second provider becomes usable by
| the running application without one line changing under Actions/ or Models/.
| The difference is the whole requirement — a registry that resolves perfectly
| while every Action still type-hints ManualTransferProvider satisfies the first
| and fails the second.
*/

it('accepts a second provider with one line of registration', function (): void {
    $second = new FakePaymentProvider;
    $second->identifier = 'paymob';

    // The one line. Everything below is the running application, untouched.
    app()->tag([FakePaymentProvider::class], 'payment.providers');
    app()->instance(FakePaymentProvider::class, $second);

    // Rebuilt because the registry is a singleton resolved once per container —
    // exactly as a deployment would build it at boot with both tags present.
    app()->forgetInstance(PaymentProviderRegistry::class);

    $registry = app(PaymentProviderRegistry::class);

    expect($registry->has('paymob'))->toBeTrue()
        ->and($registry->get('paymob'))->toBe($second)
        // The incumbent is still there: a new provider must not replace the one
        // students are currently paying through.
        ->and($registry->get('manual'))->toBeInstanceOf(ManualTransferProvider::class);
});

it('names no concrete provider inside business logic', function (): void {
    $forbidden = ['ManualTransferProvider', 'FakePaymentProvider', 'paymob', 'PaymentProviderRegistry'];

    $files = Finder::create()
        ->files()
        ->in(app_path('Modules'))
        ->path('/Actions/')
        ->name('*.php');

    $offenders = [];

    foreach ($files as $file) {
        $contents = $file->getContents();

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getRelativePathname().' → '.$needle;
            }
        }
    }

    // ⚠️ The registry itself is on the forbidden list on purpose. An Action that
    // resolves a provider BY NAME from the registry has the same dependency the
    // interface exists to remove — it just spells it as a string. The provider
    // reaches an Action already resolved, from the transaction it belongs to.
    expect($offenders)->toBe([]);
});
