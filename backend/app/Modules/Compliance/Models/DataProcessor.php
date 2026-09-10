<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\BaseModel;
use App\Modules\Compliance\Enums\ErasureCapability;
use App\Shared\Traits\HasUuid;
use Spatie\Translatable\HasTranslations;

/**
 * A third party that receives personal data — PLATFORM reference data (layer ب).
 *
 * See {@see DataCategory} for why there is no `BelongsToWorkspace`.
 *
 * @property string $key
 * @property string $name
 * @property string $purpose
 * @property string $processing_location
 * @property list<string> $categories
 * @property ErasureCapability $erasure_capability
 * @property bool $is_active
 */
class DataProcessor extends BaseModel
{
    use HasTranslations, HasUuid;

    /** @var list<string> */
    public array $translatable = ['purpose'];

    protected $fillable = [
        'key',
        'name',
        'purpose',
        'processing_location',
        'categories',
        'erasure_capability',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'erasure_capability' => ErasureCapability::class,
            'is_active' => 'boolean',
        ];
    }
}
