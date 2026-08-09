<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Models\CreditPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditPackage>
 */
class CreditPackageFactory extends Factory
{
    protected $model = CreditPackage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'باقة ٨ حصص',
            'credits' => 8,
            'session_type' => ClassSessionType::Individual,
            // Null means never expires — the launch default (Q-5). A factory
            // that dated every package would make expiry the tested default and
            // the shipped behaviour the untested one.
            'validity_days' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function ofSize(int $credits): self
    {
        return $this->state(fn (): array => [
            'name' => "باقة {$credits} حصص",
            'credits' => $credits,
        ]);
    }
}
