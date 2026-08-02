<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $weekly_reports
 * @property bool $session_alerts
 */
class NotificationPreference extends BaseModel
{
    use HasUuid;

    protected $fillable = ['user_id', 'weekly_reports', 'session_alerts'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'weekly_reports' => 'boolean',
            'session_alerts' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
