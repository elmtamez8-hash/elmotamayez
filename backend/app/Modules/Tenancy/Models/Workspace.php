<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Tenancy\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $type
 */
class Workspace extends BaseModel
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'owner_user_id',
        'settings',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id === $user->getKey();
    }
}
