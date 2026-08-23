<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Community\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message — WORKSPACE-owned (layer 2).
 *
 * ⚠️ NO `SoftDeletes`, AND THE COLUMN IS NAMED `hidden_at` TO KEEP IT THAT WAY.
 * A `deleted_at` here would add `deleted_at IS NULL` to every read through a
 * global scope — erasing the moderation archive `FR-015` promises, and shortening
 * every page of fifty in silence because `LIMIT` would apply to rows the scope
 * had already removed. Hiding is a filter callers ask for by name.
 *
 * @property int $workspace_id
 * @property int $conversation_id
 * @property int $sender_user_id
 * @property string $body
 * @property CarbonImmutable|null $hidden_at
 * @property bool $is_helpful
 */
class Message extends BaseModel
{
    /** @use HasFactory<MessageFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'sender_user_id',
        'body',
    ];

    /*
    | ⚠️ DECLARED PROPERTIES, NOT ATTRIBUTES. `ChatRankStamper` fills these for a
    | whole page in one read; assigned as dynamic attributes they would enter
    | `$attributes`, be offered to a later `save()`, and fail on columns that do
    | not exist. Declared, they are ordinary PHP properties that Eloquent's
    | `__set` never sees. `WithholdingReader::stamp()` does the same.
    */

    /** The sender's place on this teacher's board, or null for anyone not on one. */
    public ?int $senderRank = null;

    /** The sender's cumulative level, or null for a teacher, an assistant, or a first day. */
    public ?int $senderLevel = null;

    // `hidden_at` and `is_helpful` are claimed by their own Actions, each with a
    // conditional update behind it; neither is mass-assignable.

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'hidden_at' => 'immutable_datetime',
            'is_helpful' => 'boolean',
        ];
    }

    /**
     * Messages a participant may see.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
