<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Modules\Gamification\Enums\BadgeRuleType;
use App\Modules\Gamification\Models\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Badge> */
class BadgeFactory extends Factory
{
    protected $model = Badge::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'badge_'.Str::lower(Str::random(10)),
            'name' => 'شارة تجريبية',
            'icon' => 'star',
            'rule_type' => BadgeRuleType::TotalXp,
            'rule_value' => 100,
            'rule_action_key' => null,
            'is_active' => true,
        ];
    }

    public function countingAction(string $actionKey, int $times): self
    {
        return $this->state(fn (): array => [
            'rule_type' => BadgeRuleType::ActionCount,
            'rule_action_key' => $actionKey,
            'rule_value' => $times,
        ]);
    }
}
