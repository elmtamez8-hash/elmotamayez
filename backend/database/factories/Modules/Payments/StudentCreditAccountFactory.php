<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Models\User;
use App\Modules\Payments\Models\StudentCreditAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentCreditAccount>
 */
class StudentCreditAccountFactory extends Factory
{
    protected $model = StudentCreditAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }
}
