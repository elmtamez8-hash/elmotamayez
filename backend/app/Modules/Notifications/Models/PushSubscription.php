<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Notifications\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One device's standing permission to be woken (spec 012 · US2).
 *
 * ⚠️ NO `BelongsToWorkspace`, DELIBERATELY. A phone belongs to a person, not to a
 * teacher; scoped, a student studying with three teachers would carry three rows
 * for one device and be pushed three times. `PlatformOwnershipTest` fails the
 * build over the trait appearing here.
 *
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $p256dh
 * @property string $auth
 * @property string|null $user_agent
 * @property Carbon|null $last_used_at
 */
class PushSubscription extends BaseModel
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'user_agent',
        'last_used_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The indexed form of an endpoint.
     *
     * ⚠️ COMPUTED HERE AND NEVER ACCEPTED FROM A REQUEST. A client-supplied hash
     * is a client choosing which row it collides with — which is the row-theft
     * the compound unique key exists to prevent, arriving through the front door.
     */
    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
