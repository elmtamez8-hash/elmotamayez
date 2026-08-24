<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Support\BanReader;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every conversation this person is in, newest activity first.
 *
 * ⚠️ THE STUDENT'S HALF FILTERS ON `conversation_participants` EXPLICITLY, and
 * nothing else does the job. A student is a member of no workspace, so
 * `WorkspaceContext::id()` is null and `WorkspaceScope::apply()` returns adding no
 * condition at all — a query without this filter answers 200 with every private
 * conversation on the platform in it, and no refusal anywhere to notice.
 *
 * ⚠️ AND THE TEACHER'S HALF IS DERIVED FROM THE AUTHORISER'S OWN PREDICATE, not
 * assembled beside it. The nearest convenient list — the participant rows — is
 * the wrong one for them: the teacher's side has none by design. So the branch
 * asks the same two questions the door asks, membership and
 * `mayActOnStudent()`, which is what keeps the screen and the endpoint from
 * disagreeing (the `ListLeaderboardScopes` lesson).
 */
class ListConversations extends Action
{
    /** How many threads one screen shows. Beyond this, search is the answer. */
    private const LIMIT = 200;

    public function __construct(
        private readonly AssistantScopeDirectory $assistants,
        private readonly BanReader $bans,
    ) {}

    /** @return Collection<int, Conversation> */
    public function handle(User $user): Collection
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $participantIds = ConversationParticipant::query()
            ->where('user_id', $user->getKey())
            ->pluck('conversation_id')
            ->all();

        $teacherSide = $workspaceId !== null
            && $user->hasPermissionTo(Permissions::CHAT_REPLY)
            && $user->workspaces()->withoutGlobalScopes()->whereKey($workspaceId)->exists();

        if ($participantIds === [] && ! $teacherSide) {
            return collect();
        }

        $rows = Conversation::query()
            ->withoutWorkspaceScope()
            // `workspace` is what titles the row for the STUDENT — see
            // `ConversationResource::counterpartyName()`. Eager-loaded rather than
            // read per row: a `whenLoaded` key that is simply absent makes the
            // page one query cheaper and the list nameless, which a budget test
            // reads as an improvement.
            ->with(['lastMessage.sender', 'lastMessage.mediaAsset', 'student', 'workspace'])
            ->where(function (Builder $query) use ($participantIds, $teacherSide, $workspaceId): void {
                $query->whereIn('id', $participantIds);

                if ($teacherSide) {
                    $query->orWhere(function (Builder $mine) use ($workspaceId): void {
                        $mine->where('workspace_id', $workspaceId)
                            ->where('kind', ConversationKind::Private->value);
                    });
                }
            })
            // Newest activity first; a thread with nothing in it sinks to the
            // bottom rather than disappearing.
            ->orderByDesc('last_message_id')
            ->limit(self::LIMIT)
            ->get();

        if (! $teacherSide) {
            return $rows;
        }

        /*
        | ponytail: filtered in memory, and the ceiling is one page. For a teacher
        | or an owner — anyone not confined — `mayActOnStudent()` answers from a
        | per-request memo and costs nothing per row. A CONFINED assistant pays one
        | enrolment read per conversation on this screen; if that ever matters, the
        | upgrade is a directory method returning the student ids inside a scope,
        | not a second predicate written here.
        */
        $mine = $rows->filter(function (Conversation $conversation) use ($user, $participantIds): bool {
            if (in_array($conversation->getKey(), $participantIds, true)) {
                return true;
            }

            return $this->assistants->mayActOnStudent(
                $user,
                (int) $conversation->workspace_id,
                (int) $conversation->student_user_id,
            );
        })->values();

        return $this->stampBans($mine, $workspaceId);
    }

    /**
     * Whether each thread's student is banned right now — for the CONTROL, never
     * for the door.
     *
     * ⚠️ ONE QUERY FOR THE WHOLE SCREEN, AND ONLY ON THE TEACHER'S SIDE. A student
     * has no use for it and reading it for them would be telling one person about
     * another's standing. The property is public on the model rather than an
     * attribute, exactly as `ChatRankStamper` sets the sender's rank: a cast or an
     * accessor would invite a per-row read from inside the Resource, which is the
     * N+1 this method exists to avoid.
     *
     * @param  Collection<int, Conversation>  $rows
     * @return Collection<int, Conversation>
     */
    private function stampBans(Collection $rows, int $workspaceId): Collection
    {
        /** @var list<int> $studentIds */
        $studentIds = array_values(array_unique(
            $rows->pluck('student_user_id')->filter()->map(fn ($id): int => (int) $id)->all(),
        ));

        $banned = $this->bans->bannedAmong($studentIds, $workspaceId);

        foreach ($rows as $conversation) {
            $conversation->studentBanned = $banned[(int) $conversation->student_user_id] ?? false;
        }

        return $rows;
    }
}
