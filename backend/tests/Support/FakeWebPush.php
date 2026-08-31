<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;

/**
 * The seam for spec 012's push channel.
 *
 * ⚠️ `Http::fake()` CANNOT SEE A WEB PUSH, AND NEITHER CAN
 * `preventStrayRequests()`. minishlink drives its own auto-discovered PSR-18
 * client rather than Laravel's HTTP facade, so every test of this channel would
 * otherwise be measuring a real network call — and pass by timing out. The
 * container binding of `WebPush::class` is the only seam there is, which is why
 * it exists at all.
 *
 * ⚠️ AND THE PARENT CONSTRUCTOR IS DELIBERATELY NOT CALLED. `WebPush::__construct`
 * validates the VAPID pair and hunts for a PSR-18 client on the classpath; a fake
 * that ran it would fail for reasons that have nothing to do with what the test
 * is about. PHP does not chain constructors, so this is legal — the parent's
 * properties stay uninitialised because nothing below ever reads them.
 */
class FakeWebPush extends WebPush
{
    /** @var list<array{endpoint: string, payload: string|null}> */
    public array $sent = [];

    /**
     * @param  array<string, int>  $statuses  endpoint ⇒ HTTP status the push
     *                                        service answers. Anything not named
     *                                        gets `$default`.
     */
    public function __construct(
        private readonly array $statuses = [],
        private readonly int $default = 201,
    ) {
        // No parent::__construct() on purpose — see the class docblock.
    }

    public function sendOneNotification(
        SubscriptionInterface $subscription,
        ?string $payload = null,
        array $options = [],
        array $auth = [],
    ): MessageSentReport {
        $endpoint = $subscription->getEndpoint();

        $this->sent[] = ['endpoint' => $endpoint, 'payload' => $payload];

        $status = $this->statuses[$endpoint] ?? $this->default;

        return new MessageSentReport(
            new Request('POST', $endpoint),
            new Response($status),
            $status >= 200 && $status < 300,
            'HTTP '.$status,
        );
    }

    /** How many pushes reached one endpoint. */
    public function countTo(string $endpoint): int
    {
        return count(array_filter($this->sent, static fn (array $one): bool => $one['endpoint'] === $endpoint));
    }
}
