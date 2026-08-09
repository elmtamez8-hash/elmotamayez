<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $status
 * @property string $provider
 * @property OrderKind $kind
 * @property-read Workspace $workspace workspace_id is NOT NULL
 * @property-read User $user user_id is NOT NULL
 */
class Order extends BaseModel implements HasMedia
{
    use BelongsToWorkspace, HasUuid, InteractsWithMedia;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'product_id',
        'course_id',
        'kind',
        'amount',
        'currency',
        'provider',
        'provider_ref',
        'status',
        'rejection_reason',
        'approved_by',
        'approved_at',
        'metadata',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'kind' => OrderKind::class,
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** A credit purchase, which only the platform may approve. */
    public function isCreditPurchase(): bool
    {
        return $this->kind === OrderKind::Credits;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipt')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'application/pdf'])
            ->useDisk('local');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'under_review'], true);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
