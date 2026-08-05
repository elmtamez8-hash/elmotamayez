<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Media;

use App\Modules\Media\Models\PlaybackGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlaybackGrant> */
class PlaybackGrantFactory extends Factory
{
    protected $model = PlaybackGrant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'expires_at' => now()->addMinutes(5),
            'renewed_count' => 0,
            'created_at' => now(),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
