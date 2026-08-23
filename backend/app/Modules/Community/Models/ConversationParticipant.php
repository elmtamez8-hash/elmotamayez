<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use Database\Factories\Modules\Community\ConversationParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's explicit membership of a conversation.
 *
 * ⚠️ NO `BelongsToWorkspace` AND NO `HasUuid`, for the two reasons
 * `AssistantScope` records: the row carries no tenant key because it is reachable
 * only through a conversation that does, and nothing addresses it from outside —
 * a participant is created and read, never named in a URL.
 *
 * @property int $conversation_id
 * @property int $user_id
 * @property int|null $last_read_message_id
 */
class ConversationParticipant extends BaseModel
{
    /** @use HasFactory<ConversationParticipantFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'last_read_message_id',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
