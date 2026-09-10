<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\LevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Translatable\HasTranslations;

/**
 * An experience threshold with an Arabic name — PLATFORM reference data (layer ب).
 *
 * ⚠️ `$name` IS DECLARED HERE BECAUSE THE COLUMN IS JSON. Larastan types a
 * property from the migration, where a translatable column is a `json`, so
 * without this line every reader of the accessor is «undefined property» —
 * `HasTranslations` returns the locale's string, never the document.
 *
 * @property string $name
 * @property int $level
 * @property int $xp_threshold
 */
class Level extends BaseModel
{
    /** @use HasFactory<LevelFactory> */
    use HasFactory, HasTranslations, HasUuid;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'level',
        'name',
        'xp_threshold',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'xp_threshold' => 'integer',
        ];
    }
}
