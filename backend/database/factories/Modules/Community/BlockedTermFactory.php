<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Modules\Community\Enums\TermPolicy;
use App\Modules\Community\Models\BlockedTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BlockedTerm> */
class BlockedTermFactory extends Factory
{
    protected $model = BlockedTerm::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'term' => 'كلمة'.$this->faker->unique()->numberBetween(1, 99999),
            'policy' => TermPolicy::Block,
        ];
    }
}
