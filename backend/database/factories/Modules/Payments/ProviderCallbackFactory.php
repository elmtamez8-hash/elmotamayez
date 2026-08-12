<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Models\ProviderCallback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCallback>
 */
class ProviderCallbackFactory extends Factory
{
    protected $model = ProviderCallback::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Null by default, because that is what an arriving callback is: the
            // tenant is resolved when it is processed, from the order it names.
            'workspace_id' => null,
            'payment_transaction_id' => null,
            'provider' => 'fake',
            'external_id' => 'evt_'.fake()->unique()->numberBetween(1, 999999),
            'signature_valid' => true,
            'payload' => ['reference' => 'FAKE-1'],
            'received_at' => now(),
            'processed_at' => null,
            'attempts' => 0,
            'result' => null,
        ];
    }

    /** Refused at the door: no external id, no parsed body. */
    public function refused(): static
    {
        return $this->state(fn (array $attributes) => [
            'external_id' => null,
            'signature_valid' => false,
            'result' => CallbackResult::RejectedSignature,
            'processed_at' => now(),
        ]);
    }

    /** Arrived before the transaction it names. */
    public function deferred(): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => CallbackResult::Deferred,
            'attempts' => 1,
        ]);
    }
}
