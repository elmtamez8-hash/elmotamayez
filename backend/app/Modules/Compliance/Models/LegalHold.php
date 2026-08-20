<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hold that stops an erasure — PLATFORM (layer أ).
 *
 * ⚠️ THE HOLD HAS THREE DOORS, NOT TWO, and the third is what most readers miss:
 * it can be placed WHILE AN ERASURE IS ALREADY WALKING. `erase()` runs in batches
 * over minutes, so a check made once when the job started is stale for the rest of
 * the walk — and erasure does not reverse. The Action therefore re-reads this
 * table at the head of EVERY batch.
 *
 * @property int $subject_user_id
 * @property string $reason
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $released_at
 */
class LegalHold extends BaseModel
{
    use HasUuid;

    protected $fillable = [
        'subject_user_id',
        'reason',
        'placed_by_user_id',
        'placed_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'placed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * Holds still in force.
     *
     * ⚠️ `whereNull('released_at')` AND NO SECOND COLUMN. An `is_active` boolean
     * beside a release timestamp is two answers to one question, and they diverge
     * at the first write that touches one of them — here, the divergence means an
     * erasure proceeding against a hold a court placed.
     *
     * @param  Builder<LegalHold>  $query
     */
    public function scopeInForce(Builder $query): void
    {
        $query->whereNull('released_at');
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** Whether any hold is in force for this subject, asked once per batch. */
    public static function heldFor(int $subjectUserId): bool
    {
        return self::query()->inForce()->where('subject_user_id', $subjectUserId)->exists();
    }
}
