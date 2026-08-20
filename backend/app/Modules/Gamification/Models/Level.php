<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\LevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * An experience threshold with an Arabic name — PLATFORM reference data (layer ب).
 *
 * @property int $level
 * @property int $xp_threshold
 */
class Level extends BaseModel
{
    /** @use HasFactory<LevelFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'level',
        'name_ar',
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
