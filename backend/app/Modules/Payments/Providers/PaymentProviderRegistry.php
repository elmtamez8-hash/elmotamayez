<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every registered payment provider, indexed by its identifier.
 *
 * Built from a container tag, not a config array — the same shape as
 * ChannelRegistry: a tag is type-checked, so a class that does not implement the
 * interface fails at analysis rather than at 3am, and adding a provider stays
 * one line in a module provider.
 *
 * ⚠️ AN UNKNOWN IDENTIFIER IS 404, NOT 403. A webhook URL carries the provider
 * id, so the answer to an unregistered one is a statement about a ROUTE that
 * does not exist — not about permission. 403 would confirm to a prober that the
 * id names something real and only their credentials are wrong.
 */
final class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    private array $providers = [];

    /** @param iterable<PaymentProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->identifier()] = $provider;
        }
    }

    public function has(string $identifier): bool
    {
        return isset($this->providers[$identifier]);
    }

    public function get(string $identifier): PaymentProviderInterface
    {
        return $this->providers[$identifier]
            ?? throw new NotFoundHttpException('Unknown payment provider.');
    }

    /** The provider that answers when a caller names none. */
    public function default(): PaymentProviderInterface
    {
        return $this->get((string) config('payments.default'));
    }

    /** @return list<string> */
    public function identifiers(): array
    {
        return array_keys($this->providers);
    }
}
