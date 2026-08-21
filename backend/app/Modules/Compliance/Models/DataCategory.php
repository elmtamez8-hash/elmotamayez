<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\BaseModel;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Traits\HasUuid;

/**
 * One thing the platform collects — PLATFORM reference data (layer ب).
 *
 * ⚠️ NO `BelongsToWorkspace`, AND IT MUST NOT GAIN ONE. There is one "the
 * student's name" for the product; a tenant column would give every teacher their
 * own privacy policy. The guard is the platform permission
 * `compliance.registry.manage` — see the migration.
 *
 * @property string $key
 * @property string $label_ar
 * @property string $purpose_ar
 * @property string $audience
 * @property bool $is_required
 * @property string $owning_module
 * @property string $table_name
 * @property string $column_name
 * @property int|null $retain_days
 * @property ExpiryBehaviour|null $expiry_behaviour
 * @property ErasureMode $erasure_mode
 */
class DataCategory extends BaseModel
{
    use HasUuid;

    protected $fillable = [
        'key',
        'label_ar',
        'purpose_ar',
        'audience',
        'is_required',
        'owning_module',
        'table_name',
        'column_name',
        'retain_days',
        'expiry_behaviour',
        'erasure_mode',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'retain_days' => 'integer',
            'expiry_behaviour' => ExpiryBehaviour::class,
            'erasure_mode' => ErasureMode::class,
        ];
    }

    /**
     * Whether this category is swept at all.
     *
     * Both halves are required and neither implies the other: a retention with no
     * behaviour is a duration nothing acts on, and a behaviour with no retention
     * is an action with no trigger. Either alone reads as configured and does
     * nothing.
     */
    public function expires(): bool
    {
        return $this->retain_days !== null && $this->expiry_behaviour !== null;
    }
}
