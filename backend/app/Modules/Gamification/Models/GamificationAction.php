<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\GamificationActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Translatable\HasTranslations;

/**
 * What an action is worth — PLATFORM reference data (layer ب).
 *
 * Editable from /admin without a deploy (FR-002), because the initial values are
 * explicitly provisional and get tuned after a month of real behaviour (Q4). A
 * change applies from then on and NEVER recomputes past awards (FR-003): the
 * entries froze what was actually applied.
 *
 * ⚠️ `$name` IS DECLARED HERE BECAUSE THE COLUMN IS JSON. Larastan types a
 * property from the migration, where a translatable column is a `json`, so
 * without this line every reader of the accessor is «undefined property» —
 * `HasTranslations` returns the locale's string, never the document.
 *
 * @property string $name
 * @property int $xp
 * @property int $coins
 * @property int|null $daily_cap
 * @property bool $is_active
 */
class GamificationAction extends BaseModel
{
    /** @use HasFactory<GamificationActionFactory> */
    use HasFactory, HasTranslations, HasUuid;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'key',
        'name',
        'xp',
        'coins',
        'daily_cap',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'coins' => 'integer',
            'daily_cap' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
