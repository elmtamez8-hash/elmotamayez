<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Notifications\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * One notification as the recipient sees it.
 *
 * Deliberately NOT using BelongsToWorkspace. This is a bridge entity: the guard
 * is recipient_user_id, and workspace_id is context. A global workspace scope
 * here would split a parent's single stream into one per teacher (FR-025ب), and
 * — the mirror-image bug the constitution warns about — it would make one person
 * look like several.
 *
 * Because there is no global scope, nothing protects these rows implicitly.
 * Every read path goes through forRecipient() or the policy.
 *
 * Timestamps are restated here because Larastan reads column types from the
 * migration, where they are plain `timestamp`, and does not see casts().
 *
 * @property string $type
 * @property string $title
 * @property string $body
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $recipient
 */
class Notification extends BaseModel
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasTranslations, HasUuid;

    /** @var list<string> */
    public array $translatable = ['title', 'body'];

    protected $fillable = [
        'recipient_user_id',
        'workspace_id',
        'type',
        'subject_user_id',
        // What produced this row (010 · FR-046). Fillable because it is written
        // once at creation by the only Action that writes notifications at all —
        // unlike `published_at` on an announcement, nothing later claims it.
        'source_type',
        'source_id',
        'payload',
        'title',
        'body',
        'action_url',
        'read_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function type(): NotificationType
    {
        return NotificationType::from($this->type);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * The only sanctioned way to read a feed. Named as a scope so that forgetting
     * it looks like forgetting something, rather than like an ordinary query.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForRecipient(Builder $query, User $user): Builder
    {
        return $query->where('recipient_user_id', $user->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
