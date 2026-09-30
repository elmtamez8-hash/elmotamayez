<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\CreateAnnouncement;
use App\Modules\Community\Actions\HideAnnouncement;
use App\Modules\Community\Actions\PublishAnnouncement;
use App\Modules\Community\Actions\ReadAnnouncementStats;
use App\Modules\Community\Actions\UpdateAnnouncement;
use App\Modules\Community\Data\AnnouncementData;
use App\Modules\Community\Http\Requests\SaveAnnouncementRequest;
use App\Modules\Community\Http\Resources\AnnouncementResource;
use App\Modules\Community\Models\Announcement;
use App\Modules\Community\Support\AnnouncementCourses;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * The publisher's side of an announcement (FR-042 · FR-046 · FR-047).
 *
 * ⚠️ THERE IS NO REPLY ROUTE ANYWHERE, AND THE ABSENCE IS THE REQUIREMENT
 * (FR-045). A reply endpoint on a message sent to three hundred people is a
 * three-hundred-way thread with no moderation surface and no read model — and the
 * product already has the right place for an answer, which is the private
 * conversation the student can open with one tap. Nothing here creates one.
 *
 * ⚠️ AND `{announcement}` IS AN IMPLICIT BINDING, taking the exemption the
 * `{assignment}` and `{review}` routes document: `announcements` is
 * workspace-scoped and everybody who reaches these routes is a workspace MEMBER,
 * so another teacher's uuid 404s before the policy runs. Nothing a student can
 * reach may copy it — and nothing a student can reach exists here at all, because
 * the notification centre is the recipient's whole surface.
 */
class AnnouncementController extends Controller
{
    public function __construct(
        private readonly ReadAnnouncementStats $stats,
        private readonly AssistantScopeDirectory $assistants,
        private readonly AnnouncementCourses $courses,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manage', Announcement::class);

        /*
        | ⚠️ PAGED SINCE 2026-09-27 — the whole history was loaded, hydrated and
        | counted on every visit, and a workspace announces for years. Ordered by
        | id as well, so a page is the same rows twice (two announcements in one
        | second have equal `created_at`).
        |
        | ⚠️ AND RETURNED AS THE COLLECTION ITSELF, never through
        | `response()->json(...)`, which drops `links` and `meta` in silence
        | (http-and-security.md). The body is `{data, links, meta}` now; the
        | stats are attached to the page's rows in place, before it is wrapped.
        */
        /*
        | ⛔ A CONFINED ASSISTANT LISTS WHAT THEY COULD HAVE WRITTEN (spec 010 ·
        | FR-005, 2026-09-30): the announcements addressed through their own
        | courses, by anybody — the rows `AnnouncementPolicy::manage()` lets them
        | publish, edit and withdraw. Never an `all` one. In SQL, before the page
        | is cut, or a page comes back short and the total counts rows nobody
        | is shown.
        */
        $workspaceId = app(WorkspaceContext::class)->id();
        $scoped = $workspaceId === null
            ? null
            : $this->assistants->scopedCourseIdsFor($this->currentUser($request), $workspaceId);

        $page = Announcement::query()
            ->when($scoped !== null, fn ($query) => $this->courses->within($query, $scoped ?? [], (int) $workspaceId))
            ->with('author:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50);

        $this->withStats($page->getCollection());

        return AnnouncementResource::collection($page);
    }

    public function store(SaveAnnouncementRequest $request, CreateAnnouncement $action): JsonResponse
    {
        $this->authorize('manage', Announcement::class);

        $announcement = $action->handle(
            $this->currentUser($request),
            AnnouncementData::fromArray($request->validated()),
        );

        return response()->json(
            AnnouncementResource::make($this->withStats(collect([$announcement]))->first()),
            201,
        );
    }

    /**
     * Publishing is idempotent by a conditional UPDATE inside the Action, so a
     * second press answers 200 with the same row and starts no second fan-out.
     */
    public function publish(Request $request, Announcement $announcement, PublishAnnouncement $action): JsonResponse
    {
        $this->authorize('manage', $announcement);

        return response()->json(
            AnnouncementResource::make($this->withStats(collect([$action->handle($announcement)]))->first()),
        );
    }

    public function update(SaveAnnouncementRequest $request, Announcement $announcement, UpdateAnnouncement $action): JsonResponse
    {
        $this->authorize('manage', $announcement);

        $data = AnnouncementData::fromArray($request->validated());

        return response()->json(
            AnnouncementResource::make(
                $this->withStats(collect([$action->handle($announcement, $data->body, $data->isUrgent)]))->first(),
            ),
        );
    }

    public function destroy(Request $request, Announcement $announcement, HideAnnouncement $action): JsonResponse
    {
        $this->authorize('manage', $announcement);

        return response()->json(
            AnnouncementResource::make($this->withStats(collect([$action->handle($announcement)]))->first()),
        );
    }

    /**
     * ⚠️ ONE GROUPED QUERY FOR THE PAGE, NEVER A COUNT INSIDE THE RESOURCE. A
     * Resource runs once per row, so counting there is an N+1 by construction —
     * twice over, since the screen shows both numbers.
     *
     * @param  Collection<int, Announcement>  $announcements
     * @return Collection<int, Announcement>
     */
    private function withStats(Collection $announcements): Collection
    {
        $stats = $this->stats->forMany($announcements);

        return $announcements->each(function (Announcement $announcement) use ($stats): void {
            $announcement->setAttribute('stats', $stats[(int) $announcement->getKey()] ?? ['notified' => 0, 'read' => 0]);
        });
    }
}
