<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Store;

use App\Models\User;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreOrder>
 *
 * ⚠️ `fulfilled_at` AND `first_accessed_at` ARE NOT `$fillable`, so the two
 * states below force them. Both are claimed by a conditional UPDATE in
 * production; a fixture is the one place they may be written directly.
 */
class StoreOrderFactory extends Factory
{
    protected $model = StoreOrder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'store_item_id' => StoreItem::factory(),
            'buyer_user_id' => User::factory(),

            /*
             * ⚠️ BUILT HERE RATHER THAN WITH `Order::factory()`, BECAUSE THERE IS
             * NO `OrderFactory`. Payments has seven factories and none for
             * `orders` — every test in that module writes the row by hand, and
             * adding one now would be a change to another module's fixtures in
             * the middle of this one's.
             *
             * The closure receives the already-resolved attributes, which is how
             * the order gets the same workspace and the same buyer as the bridge
             * row pointing at it. Two different users on the two halves of one
             * purchase is a fixture that proves whatever the reader assumes.
             */
            'order_id' => function (array $attributes): int {
                $item = StoreItem::query()
                    ->withoutWorkspaceScope()
                    ->whereKey($attributes['store_item_id'])
                    ->firstOrFail();

                $order = Order::query()->create([
                    'workspace_id' => $attributes['workspace_id'] ?? $item->workspace_id,
                    'user_id' => $attributes['buyer_user_id'],
                    'kind' => OrderKind::Store,
                    'amount_minor' => (int) $attributes['unit_price_minor'] * (int) $attributes['quantity'],
                    'currency' => $attributes['currency'],
                    'provider' => 'manual',
                    'status' => 'pending',
                ]);

                return (int) $order->getKey();
            },
            'quantity' => 1,
            'unit_price_minor' => 5_000,
            'discount_minor' => 0,
            'commission_minor' => 500,
            'teacher_net_minor' => 4_500,
            'currency' => 'QAR',
        ];
    }

    public function fulfilled(): static
    {
        return $this->afterCreating(function (StoreOrder $order): void {
            $order->forceFill(['fulfilled_at' => now()])->save();
        });
    }

    public function opened(): static
    {
        // What closes the refund window (C4): a book that has been opened has
        // been delivered.
        return $this->fulfilled()->afterCreating(function (StoreOrder $order): void {
            $order->forceFill(['first_accessed_at' => now()])->save();
        });
    }
}
