<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Media\Models\MediaAsset;
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
 * @property string|null $body Null when the message is only an attachment (`FR-060`).
 * @property int|null $media_asset_id
 * @property CarbonImmutable|null $hidden_at
 * @property bool $is_helpful
 * @property-read MediaAsset|null $mediaAsset
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
        /*
        | ⚠️ FILLABLE BECAUSE IT IS WRITTEN ONCE, AT INSERT, AND NEVER AGAIN. A
        | message's attachment is chosen when it is sent; there is no «replace the
        | picture» operation and there must not be one, because the moderation
        | archive would then describe a file that is no longer there. Contrast
        | `hidden_at` and `is_helpful` below, which are claimed by conditional
        | updates and are deliberately NOT mass-assignable.
        |
        | And a column added by a migration but not added HERE is a column that is
        | silently never written — spec 013 shipped three of those on one table,
        | with a `201` and three nulls behind it.
        */
        'media_asset_id',
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

    /**
     * The picture or the voice note, if there is one (`FR-060`).
     *
     * ⚠️ A REFERENCE INTO `Media`, WHICH IS WHAT MAKES RETENTION WORK. The asset
     * carries `archived_at` and `retain_until`, so a chat attachment ages out
     * under 013's nightly sweep like every other file on the platform. A path
     * column here would have been a second store that nothing sweeps, holding a
     * minor's photograph for ever.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
