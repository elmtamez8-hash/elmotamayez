<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Identity;

use App\Models\User;
use App\Modules\Identity\Models\ReferralCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReferralCode> */
class ReferralCodeFactory extends Factory
{
    protected $model = ReferralCode::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code' => ReferralCode::generate(),
        ];
    }
}
