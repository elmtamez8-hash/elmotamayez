<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Community\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thread — WORKSPACE-owned (layer 2).
 *
 * @property int $workspace_id
 * @property ConversationKind $kind
 * @property int|null $student_user_id
 * @property int|null $class_session_id
 * @property int|null $lesson_id
 * @property int|null $last_message_id
 */
class Conversation extends BaseModel
{
    /** @use HasFactory<ConversationFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'kind',
        'student_user_id',
        'class_session_id',
        'lesson_id',
    ];

    /*
    | ⚠️ `last_message_id` IS DELIBERATELY NOT FILLABLE. It is claimed by a
    | conditional `WHERE last_message_id IS NULL OR last_message_id < ?` update,
    | and mass-assignable it becomes a second way to move the pointer from outside
    | the statement that owns the comparison — which is the whole guard. Same
    | reason `AssistantAssignment` keeps `revoked_at` out.
    */

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => ConversationKind::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }
}
