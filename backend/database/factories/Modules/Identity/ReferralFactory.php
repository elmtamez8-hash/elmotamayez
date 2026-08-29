<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Identity;

use App\Models\User;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referral>
 *
 * ⚠️ `status` IS NOT `$fillable` — every transition in production is a
 * conditional UPDATE — so the states below force it. A fixture is the one place
 * it may be written directly.
 */
class ReferralFactory extends Factory
{
    protected $model = Referral::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'referrer_user_id' => User::factory(),
            'referred_user_id' => User::factory(),
        ];
    }

    public function completed(): static
    {
        return $this->afterCreating(function (Referral $referral): void {
            $referral->forceFill([
                'status' => ReferralStatus::Completed->value,
                'completed_at' => now(),
            ])->save();
        });
    }

    public function flagged(string $reason = 'إحالة ذاتية'): static
    {
        return $this->afterCreating(function (Referral $referral) use ($reason): void {
            $referral->forceFill([
                'status' => ReferralStatus::Flagged->value,
                'flagged_reason' => $reason,
            ])->save();
        });
    }
}
