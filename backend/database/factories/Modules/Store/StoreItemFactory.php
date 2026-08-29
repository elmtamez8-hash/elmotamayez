<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Store;

use App\Modules\Store\Enums\StoreItemKind;
use App\Modules\Store\Models\StoreItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreItem>
 *
 * ⚠️ DIGITAL BY DEFAULT, AND `stock` IS ABSENT RATHER THAN ZERO. `null` is what
 * «this thing cannot run out» means, and a factory that wrote `0` would make
 * every digital item in every fixture sold out — the branch `ClaimStock` reads
 * before it reads anything else.
 */
class StoreItemFactory extends Factory
{
    protected $model = StoreItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => StoreItemKind::Digital,
            'title' => 'مذكّرة المراجعة',
            'description' => 'ملخّص الفصل الأول.',
            'excerpt' => 'ملخّص الفصل الأول.',
            'price_minor' => 5_000,
            'currency' => 'QAR',
            'is_active' => true,
        ];
    }

    public function physical(int $stock = 10): static
    {
        // `stock` is not `$fillable` — it moves by the conditional UPDATE that
        // claims it — so a factory has to force it. That is the one sanctioned
        // exception, and it is why this state exists instead of a raw array.
        return $this->state(fn (): array => [
            'kind' => StoreItemKind::Physical,
            'shipping_fee_minor' => 1_500,
        ])->afterMaking(function (StoreItem $item) use ($stock): void {
            $item->stock = $stock;
        })->afterCreating(function (StoreItem $item) use ($stock): void {
            $item->forceFill(['stock' => $stock])->save();
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
