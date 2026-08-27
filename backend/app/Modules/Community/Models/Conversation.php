<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
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
 * @property CarbonInterface|null $locked_at
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
        'cohort_id',
    ];

    /*
    | ⚠️ `last_message_id` IS DELIBERATELY NOT FILLABLE. It is claimed by a
    | conditional `WHERE last_message_id IS NULL OR last_message_id < ?` update,
    | and mass-assignable it becomes a second way to move the pointer from outside
    | the statement that owns the comparison — which is the whole guard. Same
    | reason `AssistantAssignment` keeps `revoked_at` out.
    */

    /*
    | ⚠️ A DECLARED PROPERTY, NOT AN ATTRIBUTE — the `Message::$senderRank` rule.
    | `ListConversations::stampBans()` fills it for a whole screen in one read;
    | assigned dynamically it would enter `$attributes`, be offered to a later
    | `save()`, and fail on a column that does not exist.
    |
    | And it answers about the CONTROL, never about the door: whether the student
    | may write is decided by `BanReader` inside `ConversationPolicy::post()`, on
    | the request that writes. A stamped boolean is a second copy of that answer
    | and is as old as the page it was rendered on.
    */

    /** Whether this thread's student is banned in this workspace right now. */
    public bool $studentBanned = false;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => ConversationKind::class,
            'locked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /**
     * The workspace, which for a STUDENT is the name of the person they are
     * talking to.
     *
     * ⚠️ THE THREAD HAS NO «TEACHER» COLUMN, AND THAT IS WHY THIS IS HERE. A
     * private conversation names its student and its workspace, and the teacher's
     * side is derived from membership — so «who am I talking to» has two different
     * answers depending on who is asking, and only one of them is a user row.
     * `ConversationResource` reads this for the student's half; without it the
     * list titled every row with the reader's OWN name.
     */
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
