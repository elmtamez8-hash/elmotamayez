<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Tenancy\FeatureFlagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One switch, optionally overridden per workspace (spec 011 · FR-047).
 *
 * ⚠️ NO `BelongsToWorkspace`, RECORDED AS A DELIBERATE VIOLATION in the spec's
 * plan. The trait fills the column from the current context, which is exactly
 * what must not happen here: the `workspace_id = 0` row belongs to no workspace
 * and is the fallback EVERY reader needs, so a tenant scope would hide the only
 * row most keys have.
 *
 * The read path is {@see Flags}, which is memoised per request; this model exists
 * for the panel that writes them.
 *
 * @property string $key
 * @property int $workspace_id
 * @property bool $enabled
 * @property string|null $description
 */
class FeatureFlag extends BaseModel
{
    /** @use HasFactory<FeatureFlagFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'key',
        'workspace_id',
        'enabled',
        'description',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'workspace_id' => 'integer',
        ];
    }

    /** Whether this row is the platform default rather than one workspace's override. */
    public function isPlatformDefault(): bool
    {
        return $this->workspace_id === 0;
    }
}
