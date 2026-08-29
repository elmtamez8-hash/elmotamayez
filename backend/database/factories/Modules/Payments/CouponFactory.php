<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Enums\CouponValueKind;
use App\Modules\Payments\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 *
 * The default is a PLATFORM coupon — `workspace_id` null, no scope, no ceiling —
 * because that is the ordinary shape and because a fixture that narrows by
 * default makes every scope test pass for the wrong reason.
 *
 * ⚠️ `redemptions_count` IS NOT `$fillable`, so {@see self::used()} forces it.
 * In production it moves only inside the conditional UPDATE that claims a place;
 * a fixture is the one place it may be written directly.
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => Coupon::normaliseCode('C'.$this->faker->unique()->bothify('??####')),
            'workspace_id' => null,
            'scope_type' => null,
            'scope_uuid' => null,
            'value_kind' => CouponValueKind::Percent,
            'value' => 20,
            'starts_at' => null,
            'ends_at' => null,
            'max_redemptions' => null,
            'is_active' => true,
        ];
    }

    public function percent(int $percent): static
    {
        return $this->state(['value_kind' => CouponValueKind::Percent, 'value' => $percent]);
    }

    public function fixed(int $minor): static
    {
        return $this->state(['value_kind' => CouponValueKind::FixedMinor, 'value' => $minor]);
    }

    public function forWorkspace(int $workspaceId): static
    {
        return $this->state(['workspace_id' => $workspaceId]);
    }

    public function scopedTo(CouponScope $kind, string $uuid): static
    {
        return $this->state(['scope_type' => $kind, 'scope_uuid' => $uuid]);
    }

    public function expired(): static
    {
        return $this->state([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function notYetStarted(): static
    {
        return $this->state(['starts_at' => now()->addDay()]);
    }

    /** A ceiling with `$used` of it already gone. */
    public function used(int $max, int $used): static
    {
        return $this->state(['max_redemptions' => $max])
            ->afterCreating(function (Coupon $coupon) use ($used): void {
                $coupon->forceFill(['redemptions_count' => $used])->save();
            });
    }
}
