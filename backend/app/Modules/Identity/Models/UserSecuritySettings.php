<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two-factor state for one account, on the platform layer.
 *
 * The column names come from Filament's app-authentication contract; the storage
 * location does not, which is why User implements that contract by hand instead
 * of using Filament's traits — the traits assume the columns sit on `users`.
 *
 * @property string|null $app_authentication_secret
 * @property array<int, string>|null $app_authentication_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property CarbonInterface|null $two_factor_required_at
 */
class UserSecuritySettings extends Model
{
    protected $table = 'user_security_settings';

    protected $fillable = [
        'user_id',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_required_at',
    ];

    protected $hidden = [
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_required_at' => 'datetime',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
