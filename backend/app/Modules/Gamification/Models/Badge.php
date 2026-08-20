<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Modules\Gamification\Enums\BadgeRuleType;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\BadgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * An achievement and the rule that earns it — PLATFORM reference data (layer ب).
 *
 * Changing a rule never withdraws a badge already awarded (FR-017): what a
 * student earned under the old rule they earned.
 *
 * @property BadgeRuleType $rule_type
 * @property int $rule_value
 * @property string|null $rule_action_key
 * @property bool $is_active
 */
class Badge extends BaseModel
{
    /** @use HasFactory<BadgeFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'key',
        'name_ar',
        'icon',
        'rule_type',
        'rule_value',
        'rule_action_key',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'rule_type' => BadgeRuleType::class,
            'rule_value' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
