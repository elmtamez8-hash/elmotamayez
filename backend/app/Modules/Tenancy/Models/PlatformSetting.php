<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * A single operational value, editable from the panel.
 *
 * Platform-owned: deliberately no BelongsToWorkspace. Read it through
 * {@see PlatformSettings}, which caches and falls
 * back to config() — querying this model directly bypasses both.
 *
 * @property string $key
 * @property mixed $value
 */
class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $fillable = ['key', 'value', 'updated_by_user_id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            // `json`, not `array`: a setting is as often a scalar (a device
            // limit, a TTL) as it is a map, and the array cast names a shape
            // that half these rows do not have.
            'value' => 'json',
        ];
    }
}
