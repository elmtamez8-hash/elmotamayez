<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Modules\Community\Support\ChatRankStamper;
use App\Modules\Community\Support\CommunitySettings;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * One page of a conversation, newest page first, oldest on request.
 *
 * ⚠️ NO `paginate()`. Laravel's paginator runs a full `COUNT(*)` beside every
 * page — one extra query at EVERY size, which the budget test cannot see because
 * it is constant, while `SC-009`'s half-second at ten thousand messages is
 * exactly what a count over ten thousand rows spends. Keyset pagination by `id`
 * is also the only form that cannot skip or repeat a message when one arrives
 * mid-scroll.
 *
 * ⚠️ AND THE CURSOR IS CHECKED AGAINST THIS CONVERSATION BEFORE IT IS USED.
 * `?before={uuid}` resolved globally is an oracle: feed it another workspace's
 * message uuid and the difference between «found» and «not found» reports whether
 * that message exists. Resolved inside the conversation, an outsider's uuid is
 * simply not there.
 */
class ReadMessages extends Action
{
    public function __construct(private readonly ChatRankStamper $ranks) {}

    /**
     * @return array{0: Conversation, 1: Collection<int, Message>}
     */
    public function handle(User $reader, string $conversationUuid, ?string $before = null): array
    {
        $conversation = Conversation::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $conversationUuid)
            ->first();

        if (! $conversation instanceof Conversation) {
            throw new ModelNotFoundException('لم نجد هذه المحادثة.');
        }

        Gate::forUser($reader)->authorize('view', $conversation);

        $query = Message::query()
            ->withoutWorkspaceScope()
            ->where('conversation_id', $conversation->getKey())
            ->visible()
            // The sender's name, eager — a Resource runs once per row, so a query
            // inside it is an N+1 by construction.
            ->with('sender');

        if ($before !== null && $before !== '') {
            $cursor = Message::query()
                ->withoutWorkspaceScope()
                ->where('conversation_id', $conversation->getKey())
                ->where('uuid', $before)
                ->value('id');

            if ($cursor === null) {
                throw new ModelNotFoundException('لم نجد الرسالة التي تطلب ما قبلها.');
            }

            $query->where('id', '<', $cursor);
        }

        /** @var Collection<int, Message> $page */
        $page = $query->orderByDesc('id')
            ->limit(CommunitySettings::messagePageSize())
            ->get()
            // Read newest-first for the LIMIT, rendered oldest-first for the eye.
            ->reverse()
            ->values();

        /*
        | The rank and the level beside the name (`FR-019`) — public rooms only.
        |
        | ⚠️ ONE CALL FOR THE WHOLE PAGE, not one per row, and not in the Resource:
        | a Resource runs once per message, so a lookup inside it is an N+1 by
        | construction. And NOT in a private conversation: a badge in front of the
        | class is social pride, while the same badge in a one-to-one thread with
        | the teacher is a score attached to a private question.
        */
        if ($conversation->kind->isPublic()) {
            $this->ranks->stamp($page, (int) $conversation->workspace_id);
        }

        return [$conversation, $page];
    }
}
