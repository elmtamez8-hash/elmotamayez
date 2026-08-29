<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Store;

use App\Modules\Store\Enums\ShipmentStatus;
use App\Modules\Store\Models\Shipment;
use App\Modules\Store\Models\StoreOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 *
 * ⚠️ `status` IS NOT `$fillable` — every change is a conditional transition that
 * also notifies the buyer — so the state below forces it. A fixture is the one
 * place it may be written directly.
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'store_order_id' => StoreOrder::factory(),
            'recipient_name' => 'نورة المهندي',
            'phone' => '+97455512345',
            'address_line' => 'الدوحة · الوعب · شارع ١٢ · مبنى ٧',
        ];
    }

    public function at(ShipmentStatus $status): static
    {
        return $this->afterCreating(function (Shipment $shipment) use ($status): void {
            $shipment->forceFill([
                'status' => $status,
                'status_changed_at' => now(),
            ])->save();
        });
    }
}
