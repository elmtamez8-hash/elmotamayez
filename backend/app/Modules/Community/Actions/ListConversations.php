<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Http\Resources\ConversationResource;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Support\BanReader;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
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
        private readonly GuardianDirectory $guardians,
    ) {}

    /**
     * @param  string|null  $includeUuid  the thread the reader is opening, which is
     *                                    listed even while it holds no message
     * @return Collection<int, Conversation>
     */
    public function handle(User $user, ?string $includeUuid = null): Collection
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $participantIds = ConversationParticipant::query()
            ->where('user_id', $user->getKey())
            ->pluck('conversation_id')
            ->all();

        $teacherSide = $workspaceId !== null
            && $user->hasPermissionTo(Permissions::CHAT_REPLY)
            && $user->workspaces()->withoutGlobalScopes()->whereKey($workspaceId)->exists();

        /*
        | ⚠️ A GUARDIAN'S THREADS ARE THEIR CHILDREN'S, DERIVED FROM THE SAME
        | PREDICATE THE DOOR ASKS (2026-09-28). A guardian has no participant row
        | — the thread is the child's, and a row of their own would outlive a
        | revoked relation — so their list is «private threads whose student is a
        | child of mine under `messages`», which is exactly what
        | `ConversationPolicy::view()` admits them on.
        */
        $childIds = $this->guardians
            ->childrenOf($user, GuardianPermission::Messages)
            ->map(fn (User $child): int => (int) $child->getKey())
            ->values()
            ->all();

        if ($participantIds === [] && ! $teacherSide && $childIds === []) {
            return collect();
        }

        $rows = Conversation::query()
            ->withoutWorkspaceScope()
            // `workspace` is what titles the row for the STUDENT — see
            // `ConversationResource::counterpartyName()`. Eager-loaded rather than
            // read per row: a `whenLoaded` key that is simply absent makes the
            // page one query cheaper and the list nameless, which a budget test
            // reads as an improvement.
            ->with(['lastMessage.sender', 'lastMessage.mediaAsset', ...ConversationResource::counterpartyLoads()])
            ->where(function (Builder $query) use ($participantIds, $teacherSide, $workspaceId, $childIds): void {
                $query->whereIn('id', $participantIds);

                if ($childIds !== []) {
                    $query->orWhere(function (Builder $children) use ($childIds): void {
                        $children->whereIn('student_user_id', $childIds)
                            ->where('kind', ConversationKind::Private->value);
                    });
                }

                if ($teacherSide) {
                    $query->orWhere(function (Builder $mine) use ($workspaceId): void {
                        $mine->where('workspace_id', $workspaceId)
                            ->where('kind', ConversationKind::Private->value);
                    });
                }
            })
            /*
            | ⛔ A THREAD WITH NOTHING IN IT IS NOT LISTED (owner decision
            | 2026-09-28). Threads are born with their first message now, but the
            | rows opened empty before that stay in the table, and a list full of
            | conversations nobody wrote in is what the owner reported. The one
            | exception is the thread being opened, so its heading still resolves.
            */
            ->where(function (Builder $query) use ($includeUuid): void {
                $query->whereNotNull('last_message_id');

                if ($includeUuid !== null && $includeUuid !== '') {
                    $query->orWhere('uuid', $includeUuid);
                }
            })
            /*
            | The thread being opened first, whatever its pointer: an EMPTY one
            | sorts after every other row (NULLs last on MySQL), and past the
            | limit it would vanish from the one screen that asked for it.
            */
            ->when(
                $includeUuid !== null && $includeUuid !== '',
                fn (Builder $query) => $query->orderByRaw('CASE WHEN uuid = ? THEN 0 ELSE 1 END', [$includeUuid]),
            )
            ->orderByDesc('last_message_id')
            ->limit(self::LIMIT)
            ->get();

        foreach ($rows as $conversation) {
            $conversation->readByGuardian = $conversation->kind === ConversationKind::Private
                && in_array((int) $conversation->student_user_id, $childIds, true)
                && ! in_array($conversation->getKey(), $participantIds, true);
        }

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
            if (in_array($conversation->getKey(), $participantIds, true) || $conversation->readByGuardian) {
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
