<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Modules\Gamification\Enums\BadgeRuleType;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\BadgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Translatable\HasTranslations;

/**
 * An achievement and the rule that earns it — PLATFORM reference data (layer ب).
 *
 * Changing a rule never withdraws a badge already awarded (FR-017): what a
 * student earned under the old rule they earned.
 *
 * ⚠️ `$name` IS DECLARED HERE BECAUSE THE COLUMN IS JSON. Larastan types a
 * property from the migration, where a translatable column is a `json`, so
 * without this line every reader of the accessor is «undefined property» —
 * `HasTranslations` returns the locale's string, never the document.
 *
 * @property string $name
 * @property BadgeRuleType $rule_type
 * @property int $rule_value
 * @property string|null $rule_action_key
 * @property bool $is_active
 */
class Badge extends BaseModel
{
    /** @use HasFactory<BadgeFactory> */
    use HasFactory, HasTranslations, HasUuid;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'key',
        'name',
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
