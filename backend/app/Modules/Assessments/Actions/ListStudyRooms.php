<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\StudyRoom;
use App\Modules\Assessments\Models\StudyRoomParticipant;
use App\Modules\Tenancy\Support\Flags;
use App\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The rooms this person hosted or joined, newest first (`GET /study-rooms`).
 *
 * ⚠️ A UNION OF TWO INDEXED READS, AND THE TWO INDEXES ARE WHY. A student is a
 * member of no workspace, so `WorkspaceScope` adds no condition and there is no
 * tenant filter to lean on: the reader's own rows are the whole predicate.
 * Hosting is served by `(host_user_id, ends_at)` and joining by
 * `(user_id, joined_at)` on the participants — and the composite unique above the
 * second one cannot answer it, because a composite index is only usable from its
 * leading column. Without that index this list is a full table scan per page.
 *
 * ⚠️ AND THE HOST IS NOT AUTO-JOINED AT CREATION, which is why hosting has to be
 * one of the two halves. A host who never answers still owns the room and must
 * see it; a host who wants to play joins through the same endpoint as everybody
 * else, and passes eligibility by construction because the paper was drawn from
 * their own pool.
 *
 * ⚠️ THE FEATURE SWITCH FILTERS, IT DOES NOT REFUSE — the same shape
 * `ListAdaptiveConcepts` uses. There is no `teacher` parameter here, so «is it
 * on?» has one answer per teacher, and a 403 would be said about a page that is
 * not an error. It is applied to the QUERY rather than to the page after it: a
 * filter over an already-paginated collection leaves a `total` that counts rows
 * the reader was never shown, and a page that is sometimes short for no visible
 * reason.
 */
class ListStudyRooms extends Action
{
    public function __construct(private readonly Flags $flags) {}

    /**
     * @return LengthAwarePaginator<int, StudyRoom>
     */
    public function handle(User $reader, int $perPage = 20): LengthAwarePaginator
    {
        $joined = StudyRoomParticipant::query()
            ->withoutWorkspaceScope()
            ->where('user_id', $reader->getKey())
            ->pluck('study_room_id');

        $mine = StudyRoom::query()
            ->withoutWorkspaceScope()
            ->where(fn ($query) => $query
                ->where('host_user_id', $reader->getKey())
                ->orWhereIn('id', $joined));

        // One read for the teachers involved, then one memoised flag map each —
        // `Flags` is bound `scoped()`, so asking twice inside a request is free.
        $enabled = [];

        foreach ((clone $mine)->distinct()->pluck('workspace_id') as $workspaceId) {
            if ($this->flags->enabled(CreateStudyRoom::FLAG, (int) $workspaceId)) {
                $enabled[] = (int) $workspaceId;
            }
        }

        $page = $mine
            ->whereIn('workspace_id', $enabled === [] ? [0] : $enabled)
            ->orderByDesc('created_at')
            ->paginate(min(50, max(5, $perPage)));

        /*
        | The reader's own participation, for the score and the answered count the
        | payload carries. ONE query for the page, never one per row — a Resource
        | runs once per row, so a lookup inside it is an N+1 by construction.
        */
        $participation = StudyRoomParticipant::query()
            ->withoutWorkspaceScope()
            ->where('user_id', $reader->getKey())
            ->whereIn('study_room_id', $page->getCollection()->pluck('id')->all())
            ->get()
            ->keyBy('study_room_id');

        $page->getCollection()->each(function (StudyRoom $room) use ($participation): void {
            // Set as a relation so the Resource reads it the way it reads every
            // other eager load, and so a room the reader only HOSTS carries null
            // rather than an absent key the Resource would have to branch on.
            $room->setRelation('viewerParticipation', $participation->get($room->getKey()));
        });

        return $page;
    }
}
