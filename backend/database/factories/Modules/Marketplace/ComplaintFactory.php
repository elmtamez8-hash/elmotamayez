<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\Complaint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Complaint> */
class ComplaintFactory extends Factory
{
    protected $model = Complaint::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reported_by' => User::factory(),
            'reason' => fake()->sentence(),
            'status' => Complaint::STATUS_OPEN,
        ];
    }

    public function confirmed(): self
    {
        return $this->state([
            'status' => Complaint::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }
}
